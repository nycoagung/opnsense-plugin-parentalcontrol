#!/usr/local/bin/php
<?php
/**
 * Table-driven tests for the messaging block's pure functions: the per-device
 * decision, the IPv4 helpers, the range-list parser and the builders of the
 * server list.
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
check('decision: plugin disabled is checked first',
    messagingDecision(false, false, 'on', [true, 'x']),
    [false, 'plugin disabled']);
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
check('cidr: outside', ipv4InCidr('203.0.113.7', '198.51.100.0/24'), false);
/* 'outside' is far from the range, so a mask one bit too wide or too narrow
   still gets it right; the two halves of a documentation /24 are next to each
   other, so one of these goes wrong */
check('cidr: a /25 covers only its own half',
    [ipv4InCidr('192.0.2.200', '192.0.2.0/25'), ipv4InCidr('192.0.2.200', '192.0.2.128/25')],
    [false, true]);
check('cidr: bare address', ipv4InCidr('192.0.2.9', '192.0.2.9'), true);
/* a bare address that was widened would still match itself, so try its neighbour */
check('cidr: bare address is a /32', ipv4InCidr('192.0.2.10', '192.0.2.9'), false);
check('cidr: zero prefix', ipv4InCidr('203.0.113.1', '0.0.0.0/0'), true);
check('cidr: garbage',
    [ipv4InCidr('not-an-ip', '192.0.2.0/24'), ipv4InCidr('192.0.2.1', '192.0.2.0/33')],
    [false, false]);
/* /0 matches every address, so only the address check can turn this one away */
check('cidr: invalid ip never matches 0.0.0.0/0', ipv4InCidr('not-an-ip', '0.0.0.0/0'), false);
check('cidr: ipv6 ignored', ipv4InCidr('2001:db8::1', '2001:db8::/32'), false);
check('cidr valid',
    [isIpv4Cidr('192.0.2.1'), isIpv4Cidr('198.51.100.0/24'),
     isIpv4Cidr('198.51.100.0/33'), isIpv4Cidr('2001:db8::/32'), isIpv4Cidr('x')],
    [true, true, false, false, false]);
check('cidr valid: malformed prefix',
    [isIpv4Cidr('192.0.2.0/abc'), isIpv4Cidr('192.0.2.0/24/8')],
    [false, false]);
check('cidr valid: /32 is allowed', isIpv4Cidr('192.0.2.0/32'), true);
/* ip2long() reads this as 192.0.2.10, inet_aton as 192.0.2.8 */
check('cidr valid: leading zero rejected', isIpv4Cidr('192.0.2.010'), false);

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

/* ---- filterCandidates ------------------------------------------------------ */

check('filter: protected and cdn',
    filterCandidates(['198.51.100.7', '203.0.113.9', '192.0.2.1', '192.0.2.1'],
        ['203.0.113.9'], ['192.0.2.0/24']),
    ['kept' => ['198.51.100.7'],
     'dropped' => ['192.0.2.1' => 'shared CDN', '203.0.113.9' => 'protected']]);
check('filter: protected wins',
    filterCandidates(['192.0.2.5'], ['192.0.2.5'], ['192.0.2.0/24'])['dropped'],
    ['192.0.2.5' => 'protected']);
check('filter: kept is sorted and unique',
    filterCandidates(['198.51.100.9', '198.51.100.7', '198.51.100.9'], [], [])['kept'],
    ['198.51.100.7', '198.51.100.9']);

/* ---- mergeLearned ---------------------------------------------------------- */

$now = 1000000000;
$day = 86400;
$maxAge = 7 * $day;

check('merge: fresh added',
    mergeLearned([], ['198.51.100.7'], $now, $maxAge),
    ['198.51.100.7' => $now]);
check('merge: stale kept',
    mergeLearned(['198.51.100.7' => $now - 6 * $day], [], $now, $maxAge),
    ['198.51.100.7' => $now - 6 * $day]);
check('merge: expired',
    mergeLearned(['198.51.100.7' => $now - $maxAge - 1], [], $now, $maxAge),
    []);
check('merge: same hour unchanged',
    mergeLearned(['198.51.100.7' => $now], ['198.51.100.7'], $now, $maxAge),
    ['198.51.100.7' => $now]);
/* the next three pin rules from the interface that a wrong version could break
   without failing any check above */
check('merge: seen again refreshes last-seen',
    mergeLearned(['198.51.100.7' => $now - 6 * $day], ['198.51.100.7'], $now, $maxAge),
    ['198.51.100.7' => $now]);
check('merge: exactly at the limit is kept',
    mergeLearned(['198.51.100.7' => $now - $maxAge], [], $now, $maxAge),
    ['198.51.100.7' => $now - $maxAge]);
check('merge: sorted by address',
    mergeLearned(['198.51.100.9' => $now], ['198.51.100.7'], $now, $maxAge),
    ['198.51.100.7' => $now, '198.51.100.9' => $now]);

/* ---- choosePublished ------------------------------------------------------- */

check('published: fresh wins',
    choosePublished(['198.51.100.0/24'], ['203.0.113.0/24'], ['192.0.2.0/24']),
    ['198.51.100.0/24']);
check('published: keeps last good',
    choosePublished([], ['203.0.113.0/24'], ['192.0.2.0/24']),
    ['203.0.113.0/24']);
check('published: shipped fallback',
    choosePublished([], [], ['192.0.2.0/24']),
    ['192.0.2.0/24']);

/* ---- destinationSet -------------------------------------------------------- */

check('destinations: notification ranges always present',
    destinationSet([], [], ['192.0.2.0/25'], ['203.0.113.0/28']),
    ['192.0.2.0/25', '203.0.113.0/28']);
check('destinations: union',
    destinationSet(['198.51.100.9' => $now, '198.51.100.7' => $now], ['198.51.100.0/24'],
        ['192.0.2.0/25'], ['192.0.2.0/25']),
    ['192.0.2.0/25', '198.51.100.0/24', '198.51.100.7', '198.51.100.9']);

/* ---- resolveAll ------------------------------------------------------------ */

/* A fake clock that moves only when a lookup costs time, so the budget is tested
   without sleeping and without a network. Each check below sets $t (where the
   clock starts) and $cost (milliseconds per lookup) first. */
$t = 0;
$cost = 400;
$clock = function () use (&$t) {
    return $t;
};
$slow = function (string $host) use (&$t, &$cost) {
    $t += $cost;
    return ['198.51.100.1'];
};
$frozen = fn() => 0;

check('resolve: budget',
    resolveAll(['a.example', 'b.example', 'c.example', 'd.example'], $slow, 1000, $clock),
    ['addresses' => ['198.51.100.1'], 'skipped' => ['d.example'], 'failed' => []]);
/* the clock moves only when a lookup runs, so three lookups' worth of time means
   the skipped host was never looked up: waiting is what the budget is there to stop */
$t = 0;
$cost = 400;
resolveAll(['a.example', 'b.example', 'c.example', 'd.example'], $slow, 1000, $clock);
check('resolve: no lookup once the budget is spent', $t, 3 * $cost);
/* two lookups of 500 ms use the 1000 ms budget up exactly: reaching it counts */
$t = 0;
$cost = 500;
check('resolve: budget reached exactly',
    resolveAll(['a.example', 'b.example', 'c.example'], $slow, 1000, $clock),
    ['addresses' => ['198.51.100.1'], 'skipped' => ['c.example'], 'failed' => []]);
/* the real clock reads epoch milliseconds, so the budget must count from the
   first reading, not from the clock's zero */
$t = 1700000000000;
$cost = 400;
check('resolve: budget counts from the first reading',
    resolveAll(['a.example', 'b.example', 'c.example', 'd.example'], $slow, 1000, $clock),
    ['addresses' => ['198.51.100.1'], 'skipped' => ['d.example'], 'failed' => []]);
/* readings: the start, before a, before b; a fourth would be the clock stepping
   back, which must not let c through once the budget is spent */
$readings = [0, 0, 1000, 0];
$next = 0;
$steppedBack = function () use (&$next, $readings) {
    return $readings[$next++];
};
$steady = ['a.example' => ['198.51.100.1'], 'b.example' => ['198.51.100.2'],
           'c.example' => ['198.51.100.3']];
check('resolve: once the budget is spent it stays spent',
    resolveAll(array_keys($steady), fn($h) => $steady[$h], 1000, $steppedBack),
    ['addresses' => ['198.51.100.1'], 'skipped' => ['b.example', 'c.example'], 'failed' => []]);
/* a failed host does not stop the hosts after it */
$failing = ['a.example' => ['198.51.100.1'], 'b.example' => false, 'c.example' => ['198.51.100.3']];
check('resolve: failures listed',
    resolveAll(array_keys($failing), fn($h) => $failing[$h], 1000, $frozen),
    ['addresses' => ['198.51.100.1', '198.51.100.3'], 'skipped' => [], 'failed' => ['b.example']]);
check('resolve: empty answer is not a failure',
    resolveAll(['a.example'], fn($h) => [], 1000, $frozen),
    ['addresses' => [], 'skipped' => [], 'failed' => []]);
check('resolve: ipv6 ignored',
    resolveAll(['a.example'], fn($h) => ['2001:db8::1', '198.51.100.2'], 1000, $frozen)['addresses'],
    ['198.51.100.2']);
check('resolve: addresses sorted',
    resolveAll(['a.example'], fn($h) => ['198.51.100.9', '198.51.100.7'], 1000, $frozen)['addresses'],
    ['198.51.100.7', '198.51.100.9']);

finish();
