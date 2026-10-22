"""Print Google's front-end ranges for gaming.json's shared_front_end_ranges.

    python3 -I tools/build-front-end-ranges.py

A maintainer's tool, never installed. Google publishes every range it uses at
https://www.gstatic.com/ipranges/goog.json, and the ranges its cloud customers'
servers get at https://www.gstatic.com/ipranges/cloud.json. Google's own services
are the first less the second: the front ends that Search, YouTube, Google's APIs
and every site hosted on them share. The plugin never blocks an address there for a
game, whoever's host gave it. A game served from Google Cloud answers from
cloud.json's ranges, so it stays blockable.

Prints the IPv4 networks, collapsed and sorted, as one JSON line, to replace the
"google" list in gaming.json. The other front ends in that object are documented
single addresses or ranges, kept by hand.
"""

import ipaddress
import json
import sys
import urllib.request

GOOG = 'https://www.gstatic.com/ipranges/goog.json'
CLOUD = 'https://www.gstatic.com/ipranges/cloud.json'


def fetch(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'build-front-end-ranges'})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read().decode()


def ipv4_prefixes(doc):
    """The IPv4 prefixes of one of Google's range lists, in its order."""
    return [p['ipv4Prefix'] for p in doc.get('prefixes', []) if 'ipv4Prefix' in p]


def front_ends(goog, cloud):
    """goog's IPv4 networks less cloud's, collapsed and sorted, as strings."""
    left = [ipaddress.ip_network(n) for n in goog]
    for taken in (ipaddress.ip_network(n) for n in cloud):
        rest = []
        for net in left:
            if not net.overlaps(taken):
                rest.append(net)
            elif not taken.supernet_of(net):
                # two CIDR networks that overlap nest, so taken lies inside net
                rest.extend(net.address_exclude(taken))
        left = rest
    return [str(n) for n in ipaddress.collapse_addresses(left)]


def main():
    goog = ipv4_prefixes(json.loads(fetch(GOOG)))
    cloud = ipv4_prefixes(json.loads(fetch(CLOUD)))
    if not goog or not cloud:
        # an empty or changed download must not turn into an empty guard
        sys.exit('no IPv4 ranges in ' + (CLOUD if goog else GOOG))
    print(json.dumps(front_ends(goog, cloud)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
