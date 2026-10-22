"""Offline checks of tools/build-front-end-ranges.py: which of Google's published
ranges are its own front ends, shared by every Google service, and so never blocked
for a game.

    python3 -I tests/BuildFrontEndRangesTest.py

No network: the functions are handed the lists the tool would download.
"""

import importlib.util
import sys
import unittest
from pathlib import Path

sys.dont_write_bytecode = True      # importing must leave no tools/__pycache__ behind
TOOL = Path(__file__).resolve().parent.parent / 'tools/build-front-end-ranges.py'
spec = importlib.util.spec_from_file_location('build_front_end_ranges', TOOL)
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)


class FrontEnds(unittest.TestCase):
    def test_cloud_ranges_are_taken_out(self):
        self.assertEqual(tool.front_ends(['198.51.100.0/24'], ['198.51.100.128/25']), ['198.51.100.0/25'])

    def test_adjacent_ranges_are_collapsed(self):
        self.assertEqual(tool.front_ends(['192.0.2.0/25', '192.0.2.128/25'], []), ['192.0.2.0/24'])

    def test_a_cloud_range_covering_everything_leaves_nothing(self):
        self.assertEqual(tool.front_ends(['203.0.113.0/24'], ['203.0.113.0/24']), [])

    def test_ipv6_and_other_keys_are_ignored(self):
        doc = {'prefixes': [{'ipv4Prefix': '192.0.2.0/24'}, {'ipv6Prefix': '2001:db8::/32'}, {'service': 'x'}]}
        self.assertEqual(tool.ipv4_prefixes(doc), ['192.0.2.0/24'])


if __name__ == '__main__':
    unittest.main()
