"""Build messaging.json, the plugin's list of messaging and notification servers,
or check the host names in it against live DNS.

    python3 -I tools/build-messaging-data.py --out src/opnsense/scripts/OPNsense/ParentalControl/messaging.json [--wns FILE]
    python3 -I tools/build-messaging-data.py --check

A maintainer's tool, never installed. The build reads the hand-maintained
tools/messaging-sources.json and adds the lists it downloads:

- each app's published ranges (Telegram's cidr.txt), IPv4 only;
- the addresses Windows PCs reach the Windows Notification Service on, from
  Microsoft Download Center item 44238, "Windows Notification Service (WNS) VIP
  and IP Ranges". The file's address changes with every new version: find the
  current one on https://echo.rendering.prod.mscas.io/en-us/download/details.aspx?id=44238
  (the same page, rendered on the server) and update
  notifications.microsoft_ranges_url. --wns reads a local copy instead;
- the shared CDNs' ranges (Cloudflare, Fastly, Amazon CloudFront, and Akamai's
  announced prefixes from RIPEstat), merged.

A failed download, an entry that is not an address range, or a list that comes
out empty stops the build with exit status 1 before anything is written.

Where the hand-maintained data comes from: Apple's ranges from Apple support
article 102266, "If your Apple devices aren't getting Apple push notifications";
Google's host names and ports from Firebase's "Configure your Network for FCM";
the apps' host names from a probe on 2026-10-18, each kept only while --check
shows it on the network expected for it and kept by the guard.

--check resolves every app, notification and protected host name through this
machine's resolver and prints each address with its network and owner
(RIPEstat) and what the plugin's guard would do with it under the current
messaging.json: kept, protected (a protected name resolves to it) or shared
CDN. It then prints the owner of every shipped range. The output shows this
network's DNS: read it, never commit it.
"""

import argparse
import datetime
import functools
import ipaddress
import json
import re
import socket
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCES = ROOT / 'tools/messaging-sources.json'
DATA = ROOT / 'src/opnsense/scripts/OPNsense/ParentalControl/messaging.json'
CIDR = re.compile(r'\b(?:\d{1,3}\.){3}\d{1,3}/\d{1,2}\b')


def fetch(url):
    # www.cloudflare.com answers urllib's default User-Agent with HTTP 403
    request = urllib.request.Request(url, headers={'User-Agent': 'build-messaging-data'})
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def ipv4(source, ranges):
    """The IPv4 networks in ranges, unique and in address order.

    Anything that is not a network, host bits set included, raises, and so does
    a list with no IPv4 network in it: a damaged download must stop the build,
    not ship a short list.
    """
    nets = sorted({n for n in map(ipaddress.ip_network, ranges) if n.version == 4})
    if not nets:
        raise ValueError(f'{source}: no IPv4 ranges')
    return nets


def strs(nets):
    return [str(n) for n in nets]


def build(wns_file):
    src = json.loads(SOURCES.read_text())
    notes = src['notifications']
    cdn = src['shared_cdn_sources']
    apps = {
        app_id: {
            'label': app['label'],
            'hosts': app['hosts'],
            'ranges_url': app['ranges_url'],
            'ranges': strs(ipv4(app['ranges_url'], fetch(app['ranges_url']).decode().split()))
            if app['ranges_url'] else [],
        }
        for app_id, app in src['apps'].items()
    }
    wns_source = wns_file or notes['microsoft_ranges_url']
    wns = Path(wns_file).read_bytes() if wns_file else fetch(wns_source)
    cloudfront = json.loads(fetch(cdn['cloudfront']))['prefixes']
    cdn_lists = [
        ipv4(cdn['cloudflare'], fetch(cdn['cloudflare']).decode().split()),
        ipv4(cdn['fastly'], json.loads(fetch(cdn['fastly']))['addresses']),
        ipv4(cdn['cloudfront'], [p['ip_prefix'] for p in cloudfront if p['service'] == 'CLOUDFRONT']),
    ] + [ipv4(url, [p['prefix'] for p in json.loads(fetch(url))['data']['prefixes']]) for url in cdn['akamai']]
    return {
        'schema': 1,
        'generated': datetime.date.today().isoformat(),
        'apps': apps,
        'protected_hosts': src['protected_hosts'],
        'notifications': {
            'apple_ranges': notes['apple_ranges'],
            'google_hosts': notes['google_hosts'],
            'google_ports': notes['google_ports'],
            'microsoft_hosts': notes['microsoft_hosts'],
            'microsoft_ranges': strs(ipv4(wns_source, CIDR.findall(wns.decode()))),
        },
        'shared_cdn_ranges': strs(sorted(ipaddress.collapse_addresses(n for nets in cdn_lists for n in nets))),
    }


@functools.lru_cache(maxsize=None)
def ripestat(call, resource):
    return json.loads(fetch(f'https://stat.ripe.net/data/{call}/data.json?resource={resource}'))['data']


def owner(ip):
    info = ripestat('network-info', ip)
    names = [f"AS{asn} {ripestat('as-overview', 'AS' + asn)['holder']}" for asn in info['asns']]
    return f"{info['prefix']} {', '.join(names)}" if names else 'not announced'


def resolve(host):
    try:
        found = socket.getaddrinfo(host, None, socket.AF_INET, socket.SOCK_STREAM)
    except socket.gaierror:
        return []
    return sorted({f[4][0] for f in found}, key=ipaddress.ip_address)


def check():
    data = json.loads(DATA.read_text())
    notes = data['notifications']
    groups = [(app_id, app['hosts']) for app_id, app in sorted(data['apps'].items())]
    groups += [('google', notes['google_hosts']), ('microsoft', notes['microsoft_hosts']),
               ('protected', data['protected_hosts'])]
    answers = {host: resolve(host) for _, hosts in groups for host in hosts}
    # the guard of lib/messaging.php filterCandidates(): protected first, then shared CDN
    protected = {ip for host in data['protected_hosts'] for ip in answers[host]}
    cdn = [ipaddress.ip_network(c) for c in data['shared_cdn_ranges']]
    listed = {f'{app_id} ranges': app['ranges'] for app_id, app in data['apps'].items()}
    listed.update(apple_ranges=notes['apple_ranges'], microsoft_ranges=notes['microsoft_ranges'])
    listed = {name: [ipaddress.ip_network(c) for c in cidrs] for name, cidrs in listed.items()}
    for group, hosts in groups:
        for host in hosts:
            print(f'{group} {host}')
            if not answers[host]:
                print('    no answer: unknown')
            for ip in answers[host]:
                addr = ipaddress.ip_address(ip)
                if not addr.is_global:
                    print(f'    {ip:15}  not a public address, the resolver may have blocked it: unknown')
                    continue
                verdict = 'protected' if ip in protected else 'shared CDN' if any(addr in n for n in cdn) else 'kept'
                inside = [name for name, nets in listed.items() if any(addr in n for n in nets)]
                print(f'    {ip:15}  {verdict:10}  {owner(ip)}' + (f'  (in {", ".join(inside)})' if inside else ''))
    # the shipped ranges are blocked without a lookup, so their owners are checked here
    for name, nets in listed.items():
        for net in nets:
            print(f'{name} {net}  {owner(str(net.network_address))}')


def main():
    parser = argparse.ArgumentParser(description='Build messaging.json, or check its host names.')
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--out', help='write messaging.json here')
    mode.add_argument('--check', action='store_true', help='resolve every host name and report what the guard keeps')
    parser.add_argument('--wns', help='a local copy of WNSPublicIPs_*.xml instead of the pinned download')
    args = parser.parse_args()
    if args.check:
        check()
    else:
        Path(args.out).write_text(json.dumps(build(args.wns), indent=2, sort_keys=True) + '\n')


main()
