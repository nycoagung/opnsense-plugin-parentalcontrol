"""Offline checks of tools/build-messaging-data.py: which part of Microsoft's WNS
file becomes microsoft_ranges, which sync.php blocks as it is, with no guard.

    python3 -I tests/BuildMessagingDataTest.py [generator]   (default: tools/build-messaging-data.py)

No network: the generator reads the WNS file before it downloads anything, and
the checks that run it point every download at a closed port to prove it.
"""

import importlib.util
import ipaddress
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

GENERATOR = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent / 'tools/build-messaging-data.py'
spec = importlib.util.spec_from_file_location('build_messaging_data', GENERATOR)
gen = importlib.util.module_from_spec(spec)
sys.dont_write_bytecode = True      # importing must leave no tools/__pycache__ behind
spec.loader.exec_module(gen)

passed = failed = 0


def check(name, got, want):
    global passed, failed
    if json.dumps(got) == json.dumps(want):
        passed += 1
        print(f'  pass  {name}')
    else:
        failed += 1
        print(f'  FAIL  {name}  got {json.dumps(got)} want {json.dumps(want)}')


def build(xml):
    """Build with xml as the WNS file: [exit status, output written, last line of stderr]."""
    with tempfile.TemporaryDirectory() as tmp:
        wns, out = Path(tmp, 'wns.xml'), Path(tmp, 'messaging.json')
        wns.write_text(xml)
        # a download, had one been tried first, would fail at once and name itself; and
        # no colours, since the last line of the traceback is compared as plain text
        env = dict(os.environ, https_proxy='http://127.0.0.1:9', NO_COLOR='1', PYTHON_COLORS='0')
        run = subprocess.run([sys.executable, '-I', str(GENERATOR), '--wns', str(wns), '--out', str(out)],
                             capture_output=True, text=True, env=env)
        last = (run.stderr.strip().splitlines() or [''])[-1]
        return [run.returncode, out.exists(), last.replace(str(wns), 'WNS')]


CLIENT = '''<?xml version="1.0" encoding="utf-8"?>
<WNSPublicIpAddresses>
  <!-- Cloud services send from 192.0.2.0/24; devices receive from the client ranges. -->
  <CloudServiceIPs>
    <IpRange Subnet="198.51.100.0/24" />
  </CloudServiceIPs>
  <ClientIPsIPv4>
    <!-- retired: 198.51.100.128/25 -->
    <IpRange Subnet="203.0.113.64/26" />
    <IpRange Subnet="203.0.113.7/32" />
  </ClientIPsIPv4>
  <ClientIPsIPv6>
    <IpRange Subnet="2001:db8::/32" />
  </ClientIPsIPv6>
</WNSPublicIpAddresses>
'''

MISSING = '''<WNSPublicIpAddresses>
  <!-- <ClientIPsIPv4><IpRange Subnet="203.0.113.0/24" /></ClientIPsIPv4> -->
  <CloudServiceIPs>
    <IpRange Subnet="198.51.100.0/24" />
  </CloudServiceIPs>
</WNSPublicIpAddresses>
'''

EMPTY = '''<WNSPublicIpAddresses>
  <ClientIPsIPv4>
    <!-- 203.0.113.0/24 -->
  </ClientIPsIPv4>
</WNSPublicIpAddresses>
'''

check('wns: only the client section counts',
      gen.strs(gen.wns_ranges('fixture', CLIENT)),
      ['203.0.113.7/32', '203.0.113.64/26'])
check('wns: no client section stops the build',
      build(MISSING),
      [1, False, 'ValueError: WNS: no <ClientIPsIPv4> element'])
check('wns: a client section without a range stops the build',
      build(EMPTY),
      [1, False, 'ValueError: WNS: no IPv4 ranges'])


def error(fn, *args):
    """The message of the ValueError fn(*args) raises, or 'no error'."""
    try:
        fn(*args)
    except ValueError as e:
        return str(e)
    return 'no error'


RIPESTAT = json.dumps({'data': {'prefixes': [
    {'prefix': '192.0.2.0/24'}, {'prefix': '2001:db8::/32'}, {'prefix': '198.51.100.0/25'}]}})
RIPESTAT_V6 = json.dumps({'data': {'prefixes': [{'prefix': '2001:db8::/32'}]}})
NET_A = {'@startAddress': '203.0.113.0', '@endAddress': '203.0.113.127', '@handle': 'NET-A', '@name': 'EXAMPLE'}
NET_B = {'@startAddress': '198.51.100.0', '@endAddress': '198.51.100.255', '@handle': 'NET-B', '@name': 'EXAMPLE'}
ARIN_ONE = json.dumps({'nets': {'netRef': NET_A}})
ARIN_TWO = json.dumps({'nets': {'netRef': [NET_A, NET_B]}})

check('ranges: RIPEstat prefixes keep IPv4 only',
      gen.strs(gen.asn_prefixes('fixture', RIPESTAT)),
      ['192.0.2.0/24', '198.51.100.0/25'])
check('ranges: no IPv4 prefix stops the build',
      error(gen.asn_prefixes, 'fixture', RIPESTAT_V6),
      'fixture: no IPv4 ranges')
check('ranges: ARIN accepts one net as an object',
      gen.strs(gen.arin_nets('fixture', ARIN_ONE)),
      ['203.0.113.0/25'])
check('ranges: ARIN accepts a list of nets',
      gen.strs(gen.arin_nets('fixture', ARIN_TWO)),
      ['198.51.100.0/24', '203.0.113.0/25'])
check('ranges: ARIN without a net stops the build',
      error(gen.arin_nets, 'fixture', json.dumps({'nets': {}})),
      'fixture: no networks')

# app_ranges() fetches and merges an app's ranges; every download goes through the
# get() it is given, which here answers from the fixtures and records each request
requests = []


def get(url, headers=None):
    requests.append([url, headers or {}])
    if url.startswith('https://stat.ripe.net/'):
        return RIPESTAT.encode()
    if url.startswith('https://whois.arin.net/'):
        return ARIN_ONE.encode()
    return b'192.0.2.0/25\n192.0.2.128/25\n'


check('ranges: sources come from RIPEstat by AS and from ARIN by network name, merged',
      [gen.strs(gen.app_ranges('example', {'ranges_sources': [{'ripestat_asn': 64496}, {'arin_name': 'EXAMPLE'}]},
                               get)), requests],
      [['192.0.2.0/24', '198.51.100.0/25', '203.0.113.0/25'],
       [['https://stat.ripe.net/data/announced-prefixes/data.json?resource=AS64496', {}],
        ['https://whois.arin.net/rest/nets;name=EXAMPLE', {'Accept': 'application/json'}]]])
check('ranges: a published list is still read from its URL, as published',
      gen.strs(gen.app_ranges('example', {'ranges_url': 'https://lists.example/cidr.txt'}, get)),
      ['192.0.2.0/25', '192.0.2.128/25'])
check('ranges: extra ranges are read as networks',
      gen.strs(gen.extra_ranges('example', {'extra_ranges': ['198.51.100.0/24', '203.0.113.128/25']})),
      ['198.51.100.0/24', '203.0.113.128/25'])
check('ranges: an app without extra ranges has none',
      gen.extra_ranges('example', {'hosts': ['a.example']}),
      [])
check('ranges: an extra range with host bits set stops the build',
      error(gen.extra_ranges, 'example', {'extra_ranges': ['198.51.100.7/24']}),
      'example: extra range 198.51.100.7/24 is not a network address')
# Meta's per-site server names: one template per service, {site} standing for each
# server-and-site suffix (01-ams2); every answer must lie in the owner's networks
check('sites: each template is expanded for every server of every site',
      gen.site_hosts({'meta_site_hosts': ['a-shv-{site}.example', 'b-shv-{site}.example']}, ['01-ams2', '02-fra3']),
      ['a-shv-01-ams2.example', 'a-shv-02-fra3.example', 'b-shv-01-ams2.example', 'b-shv-02-fra3.example'])
check('sites: an app without per-site names has none',
      gen.site_hosts({'hosts': ['a.example']}, ['01-ams2']),
      [])
site_answers = {'a.example': ['198.51.100.9', '198.51.100.7'], 'b.example': ['198.51.100.7'], 'c.example': [],
                'd.example': ['203.0.113.9']}
def site_resolve(hosts):
    return {h: site_answers[h] for h in hosts}
sites, outside = gen.site_ranges('example', list(site_answers), [ipaddress.ip_network('198.51.100.0/24')], site_resolve)
check('sites: answers inside the owner networks become /32 ranges, each once; the rest is set aside',
      [gen.strs(sites), outside],
      [['198.51.100.7/32', '198.51.100.9/32'], ['203.0.113.9']])
check('sites: no answer inside the owner networks stops the build',
      error(gen.site_ranges, 'example', ['c.example', 'd.example'], [ipaddress.ip_network('198.51.100.0/24')], site_resolve),
      'example: none of its 2 per-site names resolved inside the owner networks')
# a DNS filter answers a blocked name with 0.0.0.0 or a local address: a build behind
# one would quietly ship a shorter list
for blocked in ['0.0.0.0', '192.168.1.2', '10.0.0.1', '127.0.0.1']:
    check(f'sites: a resolver answering {blocked} stops the build',
          error(gen.site_ranges, 'example', ['a.example', 'e.example'], [ipaddress.ip_network('198.51.100.0/24')],
                lambda hosts, ip=blocked: {'a.example': ['198.51.100.9'], 'e.example': [ip]}),
          f'example: e.example answered {blocked}, which no public server has; build from an unfiltered resolver')
# a list much shorter than the shipped one means a failing or throttled resolver
# more often than closed sites
current = {'apps': {'whatsapp': {'site_ranges': [f'198.51.100.{i}/32' for i in range(100)]}}}
def sites_of(count):
    return {'whatsapp': {'site_ranges': [f'198.51.100.{i}/32' for i in range(count)]}}
check('sites: a list that shrinks by more than a tenth stops the build',
      error(gen.check_site_counts, current, sites_of(89), False),
      'whatsapp: 89 per-site addresses, 100 in the current messaging.json; a resolver may have failed or '
      'filtered answers (--accept-drop if the drop is real)')
check('sites: a tenth less passes', gen.check_site_counts(current, sites_of(90), False), None)
check('sites: a larger drop passes when accepted', gen.check_site_counts(current, sites_of(10), True), None)
check('sites: an app new to the data passes', gen.check_site_counts({'apps': {}}, sites_of(3), False), None)
# a temporary failure is tried once more; a name that does not exist is not
def lookup_of(errnos, answer):
    calls = []
    def lookup(host, *args):
        calls.append(host)
        if len(calls) <= len(errnos):
            raise gen.socket.gaierror(errnos[len(calls) - 1], 'lookup failed')
        return [(2, 1, 6, '', (answer, 0))]
    return lookup, calls
lookup, calls = lookup_of([gen.socket.EAI_AGAIN], '203.0.113.5')
check('resolve: a temporary failure is tried once more', [gen.resolve('a.example', lookup), len(calls)],
      [['203.0.113.5'], 2])
lookup, calls = lookup_of([gen.socket.EAI_AGAIN, gen.socket.EAI_AGAIN], '203.0.113.5')
check('resolve: a second temporary failure gives no answer', [gen.resolve('a.example', lookup), len(calls)], [[], 2])
lookup, calls = lookup_of([gen.socket.EAI_NONAME], '203.0.113.5')
check('resolve: a name that does not exist is not tried again', [gen.resolve('a.example', lookup), len(calls)],
      [[], 1])
check("apps: an address in two apps' ranges stops the build",
      error(gen.check_disjoint, {'a': [ipaddress.ip_network('198.51.100.7/32')], 'b': [ipaddress.ip_network('198.51.100.0/24')]}),
      'apps a and b both hold 198.51.100.7/32')
check('apps: apps with separate ranges pass',
      gen.check_disjoint({'a': [ipaddress.ip_network('198.51.100.7/32')], 'b': [ipaddress.ip_network('198.51.100.8/32')], 'c': []}),
      None)
check('ranges: an app with no ranges has none',
      gen.app_ranges('example', {'hosts': ['a.example']}, get),
      [])
check('ranges: an app may not have both a URL and sources',
      error(gen.app_ranges, 'example', {'ranges_url': 'https://lists.example/cidr.txt',
                                        'ranges_sources': [{'ripestat_asn': 64496}]}, get),
      'example: ranges_url and ranges_sources cannot both be set')
check('ranges: an unknown source stops the build',
      error(gen.app_ranges, 'example', {'ranges_sources': [{'bgp_feed': 'x'}]}, get),
      'example: unknown range source {"bgp_feed": "x"}')

net = ipaddress.ip_network
check('ranges: a range wider than /8 stops the build',
      error(gen.check_app_ranges, 'example', [net('0.0.0.0/7')], [], []),
      'example: 0.0.0.0/7 is wider than /8')
check('ranges: a range inside a shared CDN stops the build',
      error(gen.check_app_ranges, 'example', [net('198.51.100.0/25')], [net('198.51.100.0/24')], []),
      'example: 198.51.100.0/25 overlaps a shared CDN or notification range')
check('ranges: a range overlapping a notification range stops the build',
      error(gen.check_app_ranges, 'example', [net('203.0.113.0/24')], [], [net('203.0.113.128/25')]),
      'example: 203.0.113.0/24 overlaps a shared CDN or notification range')
check('ranges: a range that is not public stops the build',
      error(gen.check_app_ranges, 'example', [net('192.0.2.0/24')], [], []),
      'example: 192.0.2.0/24 is not a public range')
# a network is not public when any part of it is special-purpose space, not only
# when its ends are: 192.0.0.0/8 holds 192.168.0.0/16
wide = ['192.0.0.0/8', '172.0.0.0/11', '100.0.0.0/9', '224.0.0.0/8']
check('ranges: a range reaching into private or special-purpose space stops the build',
      [error(gen.check_app_ranges, 'example', [net(n)], [], []) for n in wide],
      [f'example: {n} is not a public range' for n in wide])

check('owners: an owner that is not an app stops the build',
      error(gen.check_owners, {'a.example': 'nobody'}, ['a.example'], ['whatsapp']),
      'protected_owners: a.example: nobody is not an app')
check('owners: an owned name must be protected',
      error(gen.check_owners, {'b.example': 'whatsapp'}, ['a.example'], ['whatsapp']),
      'protected_owners: b.example is not in protected_hosts')
check('owners: a valid map passes',
      error(gen.check_owners, {'a.example': 'whatsapp'}, ['a.example'], ['whatsapp']),
      'no error')

print(f'\n{passed} passed, {failed} failed')
sys.exit(1 if failed else 0)
