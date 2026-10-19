"""Offline checks of tools/check-gaming-hosts.py: which answers make a game host unfit
to ship in gaming.json, where every address it resolves to gets blocked.

    python3 -I tests/CheckGamingHostsTest.py

No network: problems() judges answers handed to it, and the lookups are stood in for.
"""

import contextlib
import importlib.util
import io
import ipaddress
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

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


QUOTA = 'API count exceeded - Increase Quota with Membership'


class OtherNames(unittest.TestCase):
    """HackerTarget answers with names, one a line, with "No DNS A records found", or
    with a message: a lookup that did not happen must never pass as one that found
    no other names."""

    def names(self, reply):
        with mock.patch.object(tool, 'fetch', return_value=reply):
            return tool.other_names('203.0.113.5')

    def test_none_answer_is_no_names(self):
        self.assertEqual(self.names('No DNS A records found'), [])

    def test_names_are_listed_in_lower_case(self):
        self.assertEqual(self.names('a.example\nWWW.B.example\n'), ['a.example', 'www.b.example'])

    def test_error_answer_raises(self):
        with self.assertRaises(ValueError):
            self.names('error check your search parameter')

    def test_quota_answer_raises(self):
        with self.assertRaises(ValueError):
            self.names(QUOTA)


class Main(unittest.TestCase):
    def run_passive(self, reply):
        """main() with --passive-dns on one host answering 203.0.113.5, and HackerTarget
        answering reply: (exit code, output)."""
        with tempfile.TemporaryDirectory() as d:
            data, messaging = Path(d, 'gaming.json'), Path(d, 'messaging.json')
            data.write_text(json.dumps({'alias_suffixes': SUFFIXES,
                                        'servers': {'game': {'hosts': ['play.game.example']}}}))
            messaging.write_text(json.dumps({'shared_cdn_ranges': []}))
            with mock.patch.object(tool, 'resolve', return_value=([], ['203.0.113.5'])), \
                    mock.patch.object(tool, 'fetch', return_value=reply), \
                    contextlib.redirect_stdout(io.StringIO()) as out:
                rc = tool.main(['--data', str(data), '--messaging', str(messaging), '--passive-dns'])
        return rc, out.getvalue()

    def test_passive_dns_quota_fails_the_check(self):
        rc, out = self.run_passive(QUOTA)
        self.assertEqual(rc, 1)
        self.assertIn('passive DNS lookup of 203.0.113.5 failed', out)

    def test_another_site_on_the_address_fails(self):
        rc, out = self.run_passive('shop.example\n')
        self.assertEqual(rc, 1)
        self.assertIn('also carries shop.example', out)

    def test_a_clouds_own_name_for_the_address_passes(self):
        """Google Cloud names every address n.n.n.n.bc.googleusercontent.com, reversed,
        and AWS ec2-n-n-n-n...amazonaws.com: names of the address, not other sites."""
        for name in ('5.113.0.203.bc.googleusercontent.com', 'ec2-203-0-113-5.us-east-2.compute.amazonaws.com'):
            with self.subTest(name=name):
                self.assertEqual(self.run_passive(name + '\n')[0], 0)

    def test_a_name_of_a_neighbouring_address_still_fails(self):
        """55.113.0.203 holds 5.113.0.203 as text but is another address."""
        self.assertEqual(self.run_passive('55.113.0.203.bc.googleusercontent.com\n')[0], 1)


if __name__ == '__main__':
    unittest.main()
