#!/usr/local/bin/php
<?php
/**
 * Table-driven tests for the messaging block's pure functions: the per-device
 * decision, the IPv4 helpers and the range-list parser.
 *
 * Runs anywhere PHP does - no OPNsense, no firewall, no network. A wrong answer
 * here is silent: a child keeps chatting, or a device loses something it should
 * have kept.
 */

$lib = __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/lib';
require $lib . '/markers.php';
require $lib . '/messaging.php';

$pass = $fail = 0;

function check(string $name, mixed $got, mixed $want): void
{
    global $pass, $fail;
    if (json_encode($got) === json_encode($want)) {
        $pass++;
        echo "  pass  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name  got " . json_encode($got) . " want " . json_encode($want) . "\n";
    }
}

function finish(): never
{
    global $pass, $fail;
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

/* ---- messagingDecision: first match wins ---------------------------------- */

check('decision: plugin disabled',
    messagingDecision(false, true, 'on', [true, 'outside allowed hours']),
    [false, 'plugin disabled']);
check('decision: device disabled',
    messagingDecision(true, false, 'on', [true, 'x']),
    [false, 'device disabled']);
check('decision: off',
    messagingDecision(true, true, 'off', [true, 'x']),
    [false, 'messaging allowed']);
check('decision: on',
    messagingDecision(true, true, 'on', [false, 'x']),
    [true, 'messaging always blocked']);
check('decision: schedule outside',
    messagingDecision(true, true, 'schedule', [true, 'outside allowed hours']),
    [true, 'messaging: outside allowed hours']);
check('decision: schedule inside',
    messagingDecision(true, true, 'schedule', [false, 'within allowed hours']),
    [false, 'messaging: within allowed hours']);
check('decision: schedule fails closed',
    messagingDecision(true, true, 'schedule', [true, 'scheduled but no valid time window']),
    [true, 'messaging: scheduled but no valid time window']);
check('decision: unknown setting fails closed',
    messagingDecision(true, true, 'sometimes', [false, 'x']),
    [true, 'unknown messaging setting']);

/* ---- ipv4InCidr, isIpv4Cidr ------------------------------------------------ */

check('cidr: inside', ipv4InCidr('198.51.100.7', '198.51.100.0/24'), true);
check('cidr: outside', ipv4InCidr('198.51.101.7', '198.51.100.0/24'), false);
check('cidr: bare address', ipv4InCidr('192.0.2.9', '192.0.2.9'), true);
/* a bare address that was widened would still match itself, so try its neighbour */
check('cidr: bare address is a /32', ipv4InCidr('192.0.2.10', '192.0.2.9'), false);
check('cidr: zero prefix', ipv4InCidr('203.0.113.1', '0.0.0.0/0'), true);
check('cidr: garbage',
    [ipv4InCidr('not-an-ip', '192.0.2.0/24'), ipv4InCidr('192.0.2.1', '192.0.2.0/33')],
    [false, false]);
check('cidr: ipv6 ignored', ipv4InCidr('2001:db8::1', '2001:db8::/32'), false);
check('cidr valid',
    [isIpv4Cidr('192.0.2.1'), isIpv4Cidr('198.51.100.0/24'),
     isIpv4Cidr('198.51.100.0/33'), isIpv4Cidr('2001:db8::/32'), isIpv4Cidr('x')],
    [true, true, false, false, false]);

/* ---- ipv4InAny ------------------------------------------------------------- */

$any = ['192.0.2.0/28', '198.51.100.0/24'];
check('any: later range, no match, empty list',
    [ipv4InAny('198.51.100.7', $any), ipv4InAny('203.0.113.7', $any), ipv4InAny('203.0.113.7', [])],
    [true, false, false]);

/* ---- parseRanges ----------------------------------------------------------- */

check('ranges: mixed',
    parseRanges("198.51.100.0/24\n2001:db8::/32\n# note\n\nbogus\n203.0.113.0/28\n198.51.100.0/24\n"),
    ['198.51.100.0/24', '203.0.113.0/28']);
check('ranges: html rejected', parseRanges('<html><p>198.51.100.0/24</p></html>'), []);
/* the line above is not a valid range with or without the '<' guard; here one
   line is, so only the guard turns this page away */
check('ranges: html rejected, range on its own line', parseRanges("<html>\n198.51.100.0/24\n</html>"), []);
check('ranges: empty', parseRanges(''), []);
check('ranges: trims lines, keeps input order',
    parseRanges("  203.0.113.0/28 \r\n\t198.51.100.0/24\r\n"),
    ['203.0.113.0/28', '198.51.100.0/24']);

finish();
