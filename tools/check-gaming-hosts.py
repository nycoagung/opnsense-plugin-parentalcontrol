"""Check the game hosts in gaming.json against live DNS before a release.

    python3 -I tools/check-gaming-hosts.py [--data FILE] [--messaging FILE] [--passive-dns]

A maintainer's tool, never installed. sync.php blocks every address a game host in
gaming.json resolves to, so each host must answer with addresses of the game's own:
plain A records, or an alias ending in one of gaming.json's alias_suffixes (a load
balancer of the game's own). A host fails when it answers through any other alias
(a CDN's), with an address in messaging.json's shared CDN ranges, with an address no
public server has (FILTERED_ANSWERS, as the messaging data build uses), or not at
all. --passive-dns also asks HackerTarget's reverse lookup which other names each
address carries, and fails a host whose addresses also serve names outside the
game's own domains (a provider's generic name for the address itself does not
count), and a host whose lookup gets no list of names back, such as
once the day's free lookups are used up. Answers come from Google's public DNS, so
a local DNS filter cannot hide anything.

Prints one line per host, then its problems, and exits 1 when any host has one. The
output shows the DNS of the moment: read it, never commit it.
"""

import argparse
import importlib.util
import ipaddress
import json
import re
import sys
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'src/opnsense/scripts/OPNsense/ParentalControl/gaming.json'
MESSAGING = ROOT / 'src/opnsense/scripts/OPNsense/ParentalControl/messaging.json'


def _generator():
    """tools/build-messaging-data.py, for its FILTERED_ANSWERS: one list for both tools."""
    spec = importlib.util.spec_from_file_location('build_messaging_data', ROOT / 'tools/build-messaging-data.py')
    module = importlib.util.module_from_spec(spec)
    sys.dont_write_bytecode = True
    spec.loader.exec_module(module)
    return module


FILTERED_ANSWERS = _generator().FILTERED_ANSWERS


def problems(host, chain, addresses, suffixes, cdn):
    """What makes host unfit to ship, one line each; [] when it may ship.

    chain is the alias names its answer came through, in order, empty for plain A
    records; addresses are its IPv4 answers; suffixes are the alias endings allowed;
    cdn is the shared CDN networks."""
    found = []
    if chain and not chain[-1].endswith(tuple(suffixes)):
        found.append(f'{host} answers through {chain[-1]}, which is not a load balancer of its own')
    if not addresses:
        found.append(f'{host} has no IPv4 answer')
    for ip in addresses:
        addr = ipaddress.ip_address(ip)
        if any(addr in n for n in FILTERED_ANSWERS):
            found.append(f'{host} answered {ip}, which no public server has')
        elif any(addr in n for n in cdn):
            found.append(f'{host} answered {ip}, inside a shared CDN range')
    return found


def fetch(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'check-gaming-hosts'})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read().decode()


def resolve(host):
    """host's alias chain and IPv4 answers, from Google's public DNS (JSON API)."""
    answer = json.loads(fetch('https://dns.google/resolve?' + urllib.parse.urlencode({'name': host, 'type': 'A'})))
    records = answer.get('Answer', [])
    return ([r['data'].rstrip('.') for r in records if r.get('type') == 5],
            [r['data'] for r in records if r.get('type') == 1])


NO_NAMES = 'no dns a records found'     # HackerTarget's answer for an address without names


def other_names(ip):
    """The names HackerTarget's passive DNS has seen on ip; [] when it answers that it
    has none. Any other answer that is not names, such as its error or daily-quota
    message (its free tier allows a few dozen lookups a day), raises ValueError: a
    lookup that did not happen must not pass as one that found no other names."""
    text = fetch('https://api.hackertarget.com/reverseiplookup/?' + urllib.parse.urlencode({'q': ip}))
    names = [line.strip().lower() for line in text.splitlines() if line.strip()]
    if names == [NO_NAMES]:
        return []
    if not names or any(' ' in name for name in names):
        raise ValueError(text.strip()[:120] or 'an empty answer')
    return names


def names_the_address(name, ip):
    """Whether name is a provider's generic name for ip itself, which names no site:
    Google Cloud's reversed n.n.n.n.bc.googleusercontent.com, or AWS's
    ec2-n-n-n-n.<region>.compute.amazonaws.com."""
    octets = ip.split('.')
    return any(re.search(r'(?<![0-9])' + re.escape(sep.join(order)) + r'(?![0-9])', name)
               for sep in '.-' for order in (octets, octets[::-1]))


def main(argv=None):
    parser = argparse.ArgumentParser(description='Check the game hosts in gaming.json against live DNS.')
    parser.add_argument('--data', default=str(DATA), help='gaming.json to check')
    parser.add_argument('--messaging', default=str(MESSAGING), help='messaging.json, for its shared_cdn_ranges')
    parser.add_argument('--passive-dns', action='store_true', help='also list other names on each address')
    args = parser.parse_args(argv)
    data = json.loads(Path(args.data).read_text())
    cdn = [ipaddress.ip_network(c) for c in json.loads(Path(args.messaging).read_text())['shared_cdn_ranges']]
    suffixes = data['alias_suffixes']
    failing = 0
    for game, server in data['servers'].items():
        domains = {'.'.join(h.split('.')[-2:]) for h in server.get('hosts', [])}
        for host in server.get('hosts', []):
            chain, addresses = resolve(host)
            found = problems(host, chain, addresses, suffixes, cdn)
            if args.passive_dns:
                for ip in addresses:
                    try:
                        seen = other_names(ip)
                    except ValueError as e:
                        found.append(f'passive DNS lookup of {ip} failed: {e}')
                        continue
                    strangers = [n for n in seen
                                 if not any(n == d or n.endswith('.' + d) for d in domains)
                                 and not names_the_address(n, ip)
                                 and not n.endswith(tuple(suffixes))]
                    if strangers:
                        found.append(f'{ip} also carries {", ".join(strangers[:5])}')
            via = f'  via {chain[-1]}' if chain else ''
            print(f'{"ok " if not found else "BAD"} {game} {host} {" ".join(addresses)}{via}')
            for line in found:
                print(f'    {line}')
            failing += bool(found)
    return 1 if failing else 0


if __name__ == '__main__':
    sys.exit(main())
