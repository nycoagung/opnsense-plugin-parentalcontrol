"""Offline checks of tools/build-messaging-data.py: which part of Microsoft's WNS
file becomes microsoft_ranges, which sync.php blocks as it is, with no guard.

    python3 -I tests/BuildMessagingDataTest.py [generator]   (default: tools/build-messaging-data.py)

No network: the generator reads the WNS file before it downloads anything, and
the checks that run it point every download at a closed port to prove it.
"""

import importlib.util
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

print(f'\n{passed} passed, {failed} failed')
sys.exit(1 if failed else 0)
