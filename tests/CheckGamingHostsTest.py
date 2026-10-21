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
    def run_passive(self, reply, publisher_domains=None):
        """main() with --passive-dns on one host answering 203.0.113.5, and HackerTarget
        answering reply: (exit code, output)."""
        server = {'hosts': ['play.game.example']}
        if publisher_domains is not None:
            server['publisher_domains'] = publisher_domains
        with tempfile.TemporaryDirectory() as d:
            data, messaging = Path(d, 'gaming.json'), Path(d, 'messaging.json')
            data.write_text(json.dumps({'alias_suffixes': SUFFIXES, 'servers': {'game': server}}))
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

    def test_a_name_of_the_games_publisher_passes(self):
        """The game's own website, or its publisher's other names, may share its address."""
        for name in ('publisher.example', 'www.publisher.example'):
            with self.subTest(name=name):
                self.assertEqual(self.run_passive(name + '\n', publisher_domains=['publisher.example'])[0], 0)

    def test_a_name_that_only_ends_like_the_publishers_still_fails(self):
        """notpublisher.example is another site, not a name under publisher.example."""
        self.assertEqual(self.run_passive('notpublisher.example\n', publisher_domains=['publisher.example'])[0], 1)


class ProxyHosts(unittest.TestCase):
    """A proxy host answers through its provider's proxy, which gives that one name an
    address of its own: its answer passes the alias and shared-CDN rules, and must still
    be public."""

    def run_proxy(self, chain, addresses, passive=None):
        with tempfile.TemporaryDirectory() as d:
            data, messaging = Path(d, 'gaming.json'), Path(d, 'messaging.json')
            data.write_text(json.dumps({'alias_suffixes': SUFFIXES, 'proxy_suffixes': ['.pacloudflare.example'],
                                        'servers': {'game': {'hosts': [], 'proxy_hosts': ['gate.game.example']}}}))
            messaging.write_text(json.dumps({'shared_cdn_ranges': ['198.51.100.0/24']}))
            with mock.patch.object(tool, 'resolve', return_value=(chain, addresses)), \
                    mock.patch.object(tool, 'fetch', return_value=passive), \
                    contextlib.redirect_stdout(io.StringIO()) as out:
                rc = tool.main(['--data', str(data), '--messaging', str(messaging)]
                               + (['--passive-dns'] if passive else []))
        return rc, out.getvalue()

    def test_answer_through_a_proxy_in_a_cdn_range_passes(self):
        rc, out = self.run_proxy(['x.pacloudflare.example'], ['198.51.100.7'])
        self.assertEqual(rc, 0)
        self.assertIn('gate.game.example', out)

    def test_non_public_answer_still_fails(self):
        self.assertEqual(self.run_proxy(['x.pacloudflare.example'], ['10.0.0.7'])[0], 1)

    def test_a_record_of_its_own_fails(self):
        """An ordinary proxied record answers with the name's own A record, on a shared address."""
        self.assertEqual(self.run_proxy([], ['198.51.100.7'])[0], 1)

    def test_a_shared_cdn_alias_fails(self):
        self.assertEqual(self.run_proxy(['gate.game.example.cdn.cloudflare.example'], ['198.51.100.7'])[0], 1)

    def test_its_own_proxy_alias_is_no_other_site(self):
        """Passive DNS lists the proxy alias the name itself answers through."""
        rc, out = self.run_proxy(['x.pacloudflare.example'], ['198.51.100.7'], passive='x.pacloudflare.example\n')
        self.assertEqual(rc, 0, out)


if __name__ == '__main__':
    unittest.main()
