"""Build messaging.json, the plugin's list of messaging and notification servers,
or check the host names in it against live DNS.

    python3 -I tools/build-messaging-data.py --out src/opnsense/scripts/OPNsense/ParentalControl/messaging.json [--wns FILE] [--accept-drop]
    python3 -I tools/build-messaging-data.py --check

Build through an unfiltered, unthrottled resolver: the per-site names alone are
thousands of lookups, and a DNS filter or a resolver that limits queries would
answer some of them wrongly or not at all.

A maintainer's tool, never installed. The build reads the hand-maintained
tools/messaging-sources.json and adds the lists it downloads:

- each app's published ranges (Telegram's cidr.txt), IPv4 only;
- the ranges of apps whose servers are handed out as bare addresses or sit in
  their own registered networks, from each app's ranges_sources: an AS's
  announced IPv4 prefixes from RIPEstat ({"ripestat_asn": N}) and networks by
  name from ARIN's Whois-RWS ({"arin_name": "NAME"}), merged, and the app's
  extra_ranges, kept as written: ranges its published list leaves out, which
  sync.php blocks with whichever list it uses. Every app range
  must be no wider than /8, clear of the shared CDNs and the notification
  ranges, and public all through, reaching into no special-purpose block;
- the addresses Windows PCs reach the Windows Notification Service on: the
  <ClientIPsIPv4> element of Microsoft Download Center item 44238, "Windows
  Notification Service (WNS) VIP and IP Ranges". The file's address changes
  with every new version: find the current one on
  https://echo.rendering.prod.mscas.io/en-us/download/details.aspx?id=44238
  (the same page, rendered on the server) and update
  notifications.microsoft_ranges_url. --wns reads a local copy instead;
- the shared CDNs' ranges (Cloudflare, Fastly, Amazon CloudFront, and Akamai's
  announced prefixes from RIPEstat), merged;
- the addresses of Meta's per-site servers for the apps with meta_site_hosts:
  each template with {site} replaced by every server-and-site suffix in
  meta_sites (01-syd2), resolved through this machine's resolver, keeping the
  answers inside meta_sites' owner AS (AS32934's announced prefixes, from
  RIPEstat) as site_ranges. Meta's DNS hands a client a server at one of its many
  sites, which a minute's lookup may never see, so every site's servers are
  blocked. No address may be in two apps' ranges.

A failed download, an entry that is not an address range, a WNS file without
the element, a list that comes out empty, an app range that fails its checks,
a protected_owners entry whose name is not protected or whose owner is not
an app, a per-site answer no public server has (a filtering resolver), or a
per-site list more than a tenth shorter than the current messaging.json's
(unless --accept-drop says the drop is real) stops the build with exit status 1
before anything is written. A lookup that fails for the moment is tried once more.

protected_owners maps a protected host name to the app that owns it: the name
protects its addresses only while that app is not selected, since a selected
app's own servers are what it blocks.

Where the hand-maintained data comes from: Apple's ranges from Apple support
article 102266, "If your Apple devices aren't getting Apple push notifications";
Google's host names and ports from Firebase's "Configure your Network for FCM";
the apps' host names from a probe on 2026-10-18, each kept only while --check
shows it on the network expected for it and kept by the guard, and the apps'
fallback, gateway and message-connection names from research on 2026-10-19
(Signal's and Telegram's source code, public DNS datasets, the names a home
network's devices looked up), each checked in DNS and owned by the app. WhatsApp's
sixteen numbered edge names are all listed, since its own DNS zone rotates
each of them between two AWS Global Accelerators.

--check resolves every app, notification and protected host name through this
machine's resolver and prints each address with its network and owner
(RIPEstat) and what the plugin's guard would do with it under the current
messaging.json: kept, protected (a protected name resolves to it) or shared
CDN; for an app's own host names, a protected name it owns does not count.
It also lists which protected names each app owns, then prints the owner of
every shipped range. The output shows this network's DNS: read it, never
commit it.
"""

import argparse
import concurrent.futures
import datetime
import functools
import ipaddress
import json
import re
import socket
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCES = ROOT / 'tools/messaging-sources.json'
DATA = ROOT / 'src/opnsense/scripts/OPNsense/ParentalControl/messaging.json'
CIDR = re.compile(r'\b(?:\d{1,3}\.){3}\d{1,3}/\d{1,2}\b')
# IANA's special-purpose IPv4 blocks that are not globally reachable, and multicast.
# An app range may reach into none of them: IPv4Network.is_global judges a network by
# its ends, so it passes 192.0.0.0/8, which holds 192.168.0.0/16.
SPECIAL_PURPOSE = [ipaddress.ip_network(n) for n in (
    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
    '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
    '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4')]
# What a DNS filter answers for a name it blocks, and what no public server has: the
# plugin's NON_PUBLIC_RANGES (lib/messaging.php). A per-site answer in one of them
# means the build is behind a filter, which would quietly ship a shorter list.
FILTERED_ANSWERS = [ipaddress.ip_network(n) for n in (
    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
    '192.168.0.0/16', '224.0.0.0/4', '240.0.0.0/4')]
# A per-site list this much shorter than the current messaging.json's stops the build.
SITE_DROP = 0.9


def fetch(url, headers=None):
    # www.cloudflare.com answers urllib's default User-Agent with HTTP 403
    request = urllib.request.Request(url, headers={'User-Agent': 'build-messaging-data', **(headers or {})})
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


def wns_ranges(source, xml):
    """The IPv4 ranges devices receive Windows notifications from.

    Only the file's <ClientIPsIPv4> element counts, with comments removed: its
    other sections list where cloud services send from, which no device
    contacts, and the plugin blocks these ranges as they are. A file without
    the element, or without a range in it, stops the build.
    """
    client = re.search(r'<ClientIPsIPv4>(.*?)</ClientIPsIPv4>', re.sub(r'<!--.*?-->', '', xml, flags=re.S), re.S)
    if client is None:
        raise ValueError(f'{source}: no <ClientIPsIPv4> element')
    return ipv4(source, CIDR.findall(client.group(1)))


def asn_prefixes(source, text):
    """The IPv4 prefixes in a RIPEstat announced-prefixes answer."""
    return ipv4(source, [p['prefix'] for p in json.loads(text)['data']['prefixes']])


def arin_nets(source, text):
    """The IPv4 networks of an ARIN Whois-RWS nets;name= answer, which gives one
    net as an object and several as a list."""
    refs = json.loads(text)['nets'].get('netRef', [])
    refs = [refs] if isinstance(refs, dict) else refs
    if not refs:
        raise ValueError(f'{source}: no networks')
    return ipv4(source, [str(n) for r in refs for n in ipaddress.summarize_address_range(
        ipaddress.ip_address(r['@startAddress']), ipaddress.ip_address(r['@endAddress']))])


def app_ranges(app_id, app, get=fetch):
    """An app's shipped ranges: its published list (ranges_url), the merge of its
    ranges_sources, or none."""
    url, sources = app.get('ranges_url'), app.get('ranges_sources')
    if url and sources:
        raise ValueError(f'{app_id}: ranges_url and ranges_sources cannot both be set')
    if url:
        return ipv4(url, get(url).decode().split())
    nets = []
    for source in sources or []:
        if 'ripestat_asn' in source:
            where = f'https://stat.ripe.net/data/announced-prefixes/data.json?resource=AS{source["ripestat_asn"]}'
            nets += asn_prefixes(where, get(where).decode())
        elif 'arin_name' in source:
            where = f'https://whois.arin.net/rest/nets;name={source["arin_name"]}'
            nets += arin_nets(where, get(where, {'Accept': 'application/json'}).decode())
        else:
            raise ValueError(f'{app_id}: unknown range source {json.dumps(source)}')
    return sorted(ipaddress.collapse_addresses(nets))


def extra_ranges(app_id, app):
    """An app's extra_ranges: ranges its published list leaves out, shipped and
    blocked with whichever list sync.php uses."""
    nets = []
    for cidr in app.get('extra_ranges', []):
        try:
            nets.append(ipaddress.ip_network(cidr))
        except ValueError:
            raise ValueError(f'{app_id}: extra range {cidr} is not a network address') from None
    return nets


def site_hosts(app, sites):
    """An app's per-site server names: each meta_site_hosts template with {site}
    replaced by every server-and-site suffix (01-syd2), template by template."""
    return [template.replace('{site}', site) for template in app.get('meta_site_hosts', []) for site in sites]


def site_ranges(app_id, hosts, owned, resolve_hosts):
    """The answers of an app's per-site names that lie in its owner's networks, as
    /32 networks, each once; and the answers outside them, set aside. A name that
    does not resolve is skipped: not every service runs at every site. An answer no
    public server has (FILTERED_ANSWERS) stops the build."""
    answers = resolve_hosts(hosts)
    inside, outside = set(), set()
    for host in hosts:
        for ip in answers.get(host, []):
            addr = ipaddress.ip_address(ip)
            if any(addr in n for n in FILTERED_ANSWERS):
                raise ValueError(f'{app_id}: {host} answered {ip}, which no public server has; '
                                 'build from an unfiltered resolver')
            (inside if any(addr in n for n in owned) else outside).add(addr)
    if not inside:
        raise ValueError(f'{app_id}: none of its {len(hosts)} per-site names resolved inside the owner networks')
    return ([ipaddress.ip_network(f'{a}/32') for a in sorted(inside)],
            [str(a) for a in sorted(outside)])


def check_site_counts(current, apps, accept_drop):
    """Each app's per-site list against the current messaging.json's: one more than a
    tenth shorter stops the build, unless the drop is accepted. A failing or
    throttled resolver shortens a list more often than closing sites do, and both
    look the same to the lookups."""
    for app_id, app in apps.items():
        old = len(current.get('apps', {}).get(app_id, {}).get('site_ranges', []))
        new = len(app.get('site_ranges', []))
        if old and new < old * SITE_DROP and not accept_drop:
            raise ValueError(f'{app_id}: {new} per-site addresses, {old} in the current messaging.json; '
                             'a resolver may have failed or filtered answers (--accept-drop if the drop is real)')


def resolve_all(hosts):
    """Each host's IPv4 answers through this machine's resolver, many at a time."""
    with concurrent.futures.ThreadPoolExecutor(max_workers=32) as pool:
        return dict(zip(hosts, pool.map(resolve, hosts)))


def check_disjoint(apps):
    """No address may be in two apps' ranges: selecting one app would block the
    other. apps maps each app id to its networks. One sweep in address order: a
    network that starts inside the farthest-reaching network so far, of another
    app, overlaps it."""
    reach = None        # (last address, app id, network) reaching farthest so far
    for start, end, app_id, net in sorted((int(n.network_address), int(n.broadcast_address), app_id, n)
                                          for app_id, nets in apps.items() for n in nets):
        if reach and start <= reach[0] and app_id != reach[1]:
            pair = sorted([app_id, reach[1]])
            narrow = net if net.prefixlen >= reach[2].prefixlen else reach[2]
            raise ValueError(f'apps {pair[0]} and {pair[1]} both hold {narrow}')
        if reach is None or end > reach[0]:
            reach = (end, app_id, net)


def check_app_ranges(app_id, nets, cdn, notify):
    """sync.php blocks an app's ranges as they are, so each must be no wider than
    /8, clear of the shared CDNs and the notification ranges, and public all
    through: clear of every special-purpose block."""
    for n in nets:
        if n.prefixlen < 8:
            raise ValueError(f'{app_id}: {n} is wider than /8')
        if any(n.overlaps(c) for c in [*cdn, *notify]):
            raise ValueError(f'{app_id}: {n} overlaps a shared CDN or notification range')
        if any(n.overlaps(s) for s in SPECIAL_PURPOSE):
            raise ValueError(f'{app_id}: {n} is not a public range')


def check_owners(owners, protected, app_ids):
    """Every owned name is a protected name, and every owner an app."""
    for host, owner_app in owners.items():
        if host not in protected:
            raise ValueError(f'protected_owners: {host} is not in protected_hosts')
        if owner_app not in app_ids:
            raise ValueError(f'protected_owners: {host}: {owner_app} is not an app')


def build(wns_file, accept_drop=False):
    src = json.loads(SOURCES.read_text())
    notes = src['notifications']
    cdn = src['shared_cdn_sources']
    owners = src.get('protected_owners', {})
    # Microsoft's file first, so a bad one stops the build before any download
    wns_source = wns_file or notes['microsoft_ranges_url']
    microsoft = wns_ranges(wns_source, (Path(wns_file).read_bytes() if wns_file else fetch(wns_source)).decode())
    check_owners(owners, src['protected_hosts'], list(src['apps']))
    cloudfront = json.loads(fetch(cdn['cloudfront']))['prefixes']
    cdn_lists = [
        ipv4(cdn['cloudflare'], fetch(cdn['cloudflare']).decode().split()),
        ipv4(cdn['fastly'], json.loads(fetch(cdn['fastly']))['addresses']),
        ipv4(cdn['cloudfront'], [p['ip_prefix'] for p in cloudfront if p['service'] == 'CLOUDFRONT']),
    ] + [asn_prefixes(url, fetch(url).decode()) for url in cdn['akamai']]
    shared = sorted(ipaddress.collapse_addresses(n for nets in cdn_lists for n in nets))
    notify = [ipaddress.ip_network(c) for c in notes['apple_ranges']] + microsoft
    meta = src.get('meta_sites')
    owned = []
    if meta:
        where = f'https://stat.ripe.net/data/announced-prefixes/data.json?resource=AS{meta["owner_asn"]}'
        owned = asn_prefixes(where, fetch(where).decode())
    apps = {}
    for app_id, app in src['apps'].items():
        ranges = app_ranges(app_id, app)
        extra = extra_ranges(app_id, app)
        hosts = site_hosts(app, meta['servers'] if meta else [])
        sites = []
        if hosts:
            answers = resolve_all(hosts)
            sites, outside = site_ranges(app_id, hosts, owned, lambda _, answers=answers: answers)
            if outside:
                print(f'{app_id}: {len(outside)} per-site answers outside AS{meta["owner_asn"]} left out: '
                      + ' '.join(outside[:8]), file=sys.stderr)
            # what each template found, so a shrinking service shows by name
            for template in app['meta_site_hosts']:
                names = [template.replace('{site}', s) for s in meta['servers']]
                found = sum(1 for h in names if answers.get(h))
                print(f'{app_id}: {template}: {found} of {len(names)} names answered', file=sys.stderr)
        check_app_ranges(app_id, ranges + extra + sites, shared, notify)
        apps[app_id] = {'label': app['label'], 'hosts': app['hosts'],
                        'ranges_url': app.get('ranges_url'), 'ranges': strs(ranges)}
        if extra:
            apps[app_id]['extra_ranges'] = strs(extra)
        if sites:
            apps[app_id]['site_ranges'] = strs(sites)
    check_disjoint({app_id: [ipaddress.ip_network(c) for c in app['ranges'] + app.get('extra_ranges', [])
                             + app.get('site_ranges', [])] for app_id, app in apps.items()})
    check_site_counts(json.loads(DATA.read_text()) if DATA.exists() else {}, apps, accept_drop)
    return {
        'schema': 1,
        'generated': datetime.date.today().isoformat(),
        'apps': apps,
        'protected_hosts': src['protected_hosts'],
        'protected_owners': owners,
        'notifications': {
            'apple_ranges': notes['apple_ranges'],
            'google_hosts': notes['google_hosts'],
            'google_ports': notes['google_ports'],
            'microsoft_hosts': notes['microsoft_hosts'],
            'microsoft_ranges': strs(microsoft),
        },
        'shared_cdn_ranges': strs(shared),
    }


@functools.lru_cache(maxsize=None)
def ripestat(call, resource):
    return json.loads(fetch(f'https://stat.ripe.net/data/{call}/data.json?resource={resource}'))['data']


def owner(ip):
    info = ripestat('network-info', ip)
    names = [f"AS{asn} {ripestat('as-overview', 'AS' + asn)['holder']}" for asn in info['asns']]
    return f"{info['prefix']} {', '.join(names)}" if names else 'not announced'


def resolve(host, lookup=socket.getaddrinfo):
    """host's IPv4 answers, [] when it has none. A temporary failure is tried once
    more, so a moment's trouble at the resolver does not drop a name from a list."""
    retry = {socket.EAI_AGAIN, getattr(socket, 'EAI_FAIL', socket.EAI_AGAIN)}
    for attempt in (1, 2):
        try:
            found = lookup(host, None, socket.AF_INET, socket.SOCK_STREAM)
        except socket.gaierror as e:
            if attempt == 1 and e.errno in retry:
                continue
            return []
        return sorted({f[4][0] for f in found}, key=ipaddress.ip_address)
    return []


def check():
    data = json.loads(DATA.read_text())
    notes = data['notifications']
    groups = [(app_id, app['hosts']) for app_id, app in sorted(data['apps'].items())]
    groups += [('google', notes['google_hosts']), ('microsoft', notes['microsoft_hosts']),
               ('protected', data['protected_hosts'])]
    answers = {host: resolve(host) for _, hosts in groups for host in hosts}
    owners = data.get('protected_owners', {})
    for host, owner_app in sorted(owners.items()):
        print(f'{host}: protected unless {owner_app} is selected')
    # the guard of lib/messaging.php: protected first, then shared CDN; a protected
    # name its owner app counts against does not protect that app's own names
    def protected(group):
        return {ip for host in data['protected_hosts'] if owners.get(host) != group for ip in answers[host]}
    cdn = [ipaddress.ip_network(c) for c in data['shared_cdn_ranges']]
    listed = {f'{app_id} ranges': app['ranges'] + app.get('extra_ranges', []) for app_id, app in data['apps'].items()}
    listed.update(apple_ranges=notes['apple_ranges'], microsoft_ranges=notes['microsoft_ranges'])
    listed = {name: [ipaddress.ip_network(c) for c in cidrs] for name, cidrs in listed.items()}
    # per-site addresses were checked against their owner's networks when built
    sites = {f'{app_id} per-site ranges': [ipaddress.ip_network(c) for c in app['site_ranges']]
             for app_id, app in data['apps'].items() if app.get('site_ranges')}
    for group, hosts in groups:
        guarded = protected(group)
        for host in hosts:
            print(f'{group} {host}')
            if not answers[host]:
                print('    no answer: unknown')
            for ip in answers[host]:
                addr = ipaddress.ip_address(ip)
                if not addr.is_global:
                    print(f'    {ip:15}  not a public address, the resolver may have blocked it: unknown')
                    continue
                verdict = 'protected' if ip in guarded else 'shared CDN' if any(addr in n for n in cdn) else 'kept'
                inside = [name for name, nets in [*listed.items(), *sites.items()] if any(addr in n for n in nets)]
                print(f'    {ip:15}  {verdict:10}  {owner(ip)}' + (f'  (in {", ".join(inside)})' if inside else ''))
    # the shipped ranges are blocked without a lookup, so their owners are checked here
    for name, nets in listed.items():
        for net in nets:
            print(f'{name} {net}  {owner(str(net.network_address))}')
    for name, nets in sites.items():
        print(f'{name}: {len(nets)} addresses of per-site server names, inside their owner networks when built')


def main():
    parser = argparse.ArgumentParser(description='Build messaging.json, or check its host names.')
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--out', help='write messaging.json here')
    mode.add_argument('--check', action='store_true', help='resolve every host name and report what the guard keeps')
    parser.add_argument('--wns', help='a local copy of WNSPublicIPs_*.xml instead of the pinned download')
    parser.add_argument('--accept-drop', action='store_true',
                        help='write the file even when a per-site list is more than a tenth shorter than now')
    args = parser.parse_args()
    if args.check:
        check()
    else:
        Path(args.out).write_text(json.dumps(build(args.wns, args.accept_drop), indent=2, sort_keys=True) + '\n')


if __name__ == '__main__':
    main()
