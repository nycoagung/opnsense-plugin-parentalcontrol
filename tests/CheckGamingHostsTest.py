"""Offline checks of tools/check-gaming-hosts.py: which answers make a game host unfit
to ship in gaming.json, where every address it resolves to gets blocked.

    python3 -I tests/CheckGamingHostsTest.py

No network: problems() judges answers handed to it.
"""

import importlib.util
import ipaddress
import sys
import unittest
from pathlib import Path

sys.dont_write_bytecode = True      # importing must leave no tools/__pycache__ behind
TOOL = Path(__file__).resolve().parent.parent / 'tools/check-gaming-hosts.py'
spec = importlib.util.spec_from_file_location('check_gaming_hosts', TOOL)
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)

SUFFIXES = ['.elb.amazonaws.com']
NO_CDN = []


class Problems(unittest.TestCase):
    def test_plain_a_record_passes(self):
        self.assertEqual(tool.problems('game.example', [], ['203.0.113.5'], SUFFIXES, NO_CDN), [])

    def test_own_load_balancer_alias_passes(self):
        self.assertEqual(tool.problems('login.game.example', ['x-1.us-east-2.elb.amazonaws.com'], ['203.0.113.5'],
                                       SUFFIXES, NO_CDN), [])

    def test_cdn_alias_fails(self):
        found = tool.problems('www.game.example', ['d1.cloudfront.net'], ['203.0.113.5'], SUFFIXES, NO_CDN)
        self.assertEqual(len(found), 1)
        self.assertIn('d1.cloudfront.net', found[0])

    def test_address_in_shared_cdn_range_fails(self):
        found = tool.problems('www.game.example', [], ['198.51.100.7'], SUFFIXES,
                              [ipaddress.ip_network('198.51.100.0/24')])
        self.assertEqual(len(found), 1)
        self.assertIn('198.51.100.7', found[0])

    def test_non_public_address_fails(self):
        found = tool.problems('www.game.example', [], ['10.0.0.5'], SUFFIXES, NO_CDN)
        self.assertEqual(len(found), 1)
        self.assertIn('10.0.0.5', found[0])

    def test_no_answer_fails(self):
        self.assertEqual(len(tool.problems('gone.game.example', [], [], SUFFIXES, NO_CDN)), 1)


if __name__ == '__main__':
    unittest.main()
