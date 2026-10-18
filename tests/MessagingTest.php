#!/usr/local/bin/php
<?php
/**
 * Table-driven tests for the messaging block's pure functions: the per-device
 * decision, the IPv4 helpers, the range-list parser, the builders of the server
 * list, the choice of connections to drop, and the alias-conflict and reference
 * checks that setup and uninstall depend on.
 *
 * Runs anywhere PHP does - no OPNsense, no firewall, no network. A wrong answer
 * here is silent: a child keeps chatting, a device loses something it should
 * have kept, or a user's own rule is left pointing at an alias that was deleted.
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

/* ---- markers --------------------------------------------------------------- */

/* The notification rule takes the string and the dropping of open connections
   takes the two bounds it is built from, so a change to either changes what the
   firewall blocks. */
check('markers: notify ports', NOTIFY_PORTS, '5228-5230');

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

/* ---- isIpv4, isIpv4Cidr, ipv4InCidr ---------------------------------------- */

check('ipv4: strict dotted quad',
    [isIpv4('192.0.2.1'), isIpv4('192.0.2.010'), isIpv4('192.0.2.0/24'), isIpv4('2001:db8::1')],
    [true, false, false, false]);
/* '192.0.2.010' is .10 to ip2long() and .8 to inet_aton, so no caller may take it
   for .10: ask each of them, with a range that would catch .10 if one did */
check('ipv4: every caller uses it',
    [isIpv4Cidr('192.0.2.010'),
     ipv4InCidr('192.0.2.010', '192.0.2.10'),
     resolveAll(['a.example'], fn($h) => ['192.0.2.010'], 1000, fn() => 0)['addresses']],
    [false, false, []]);

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

/* ---- isPublicIpv4 ---------------------------------------------------------- */

/* what a resolver answers for a name it sinkholes, and the local ranges: blocking
   any of them would cut a device off from its own network */
check('public: private, loopback, unspecified, link-local and shared ranges are not',
    array_map('isPublicIpv4', ['10.0.0.1', '172.16.0.1', '192.168.1.1', '127.0.0.1', '0.0.0.0', '169.254.1.1',
        '100.64.0.1', '100.127.255.255']),
    array_fill(0, 8, false));
/* the documentation ranges stand for public addresses here, and 100.64.0.0/10 is
   no wider than a /10 */
check('public: other addresses are',
    array_map('isPublicIpv4', ['192.0.2.1', '198.51.100.1', '203.0.113.1', '100.63.255.255', '100.128.0.0']),
    array_fill(0, 5, true));
check('public: only a bare, strict address',
    array_map('isPublicIpv4', ['not-an-ip', '198.51.100.0/24', '2001:db8::1', '198.51.100.010']),
    array_fill(0, 4, false));

/* ---- canonicalTarget ------------------------------------------------------- */

/* pf stores a device's CIDR in network form, and core's state filter rejects host bits */
check('target: host bits give the network',
    [canonicalTarget('192.0.2.10/24'), canonicalTarget('198.51.100.130/25'), canonicalTarget('203.0.113.7/0'),
     canonicalTarget('192.0.2.10/024')],
    ['192.0.2.0/24', '198.51.100.128/25', '0.0.0.0/0', '192.0.2.0/24']);
check('target: an address, a network and a /32 stay as they are',
    [canonicalTarget('192.0.2.10'), canonicalTarget('192.0.2.0/24'), canonicalTarget('192.0.2.10/32')],
    ['192.0.2.10', '192.0.2.0/24', '192.0.2.10/32']);
check('target: anything else stays as it is',
    [canonicalTarget('00:00:5e:00:53:01'), canonicalTarget('2001:db8::1/64'), canonicalTarget('192.0.2.10/33'),
     canonicalTarget('')],
    ['00:00:5e:00:53:01', '2001:db8::1/64', '192.0.2.10/33', '']);

/* ---- pfTableEntry, tableDiff ----------------------------------------------- */

/* pfctl stores a network in network form and lists a /32 as a bare address */
check('pf entry: as pfctl lists it',
    array_map('pfTableEntry', ['198.51.100.7/32', '192.0.2.10/24', '192.0.2.0/24', '192.0.2.1', 'x']),
    ['198.51.100.7', '192.0.2.0/24', '192.0.2.0/24', '192.0.2.1', 'x']);
/* a /32 or a host-bit entry that pf already holds is neither added nor deleted
   again, which would take it out of the table on every other run */
check('table: entries pf already holds in its own form stay',
    tableDiff(['198.51.100.7/32', '192.0.2.10/24'], ['198.51.100.7', '192.0.2.0/24']),
    ['added' => [], 'removed' => []]);
check('table: differences, added as wanted and deleted as listed',
    tableDiff(['198.51.100.7/32', '203.0.113.0/24', '192.0.2.1'], ['198.51.100.7', '203.0.113.9', '198.51.100.0/24']),
    ['added' => ['203.0.113.0/24', '192.0.2.1'], 'removed' => ['203.0.113.9', '198.51.100.0/24']]);

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
/* no service owns more than a /8: a wider line is a broken or hostile list */
check('ranges: wider than a /8 is rejected',
    parseRanges("0.0.0.0/0\n198.0.0.0/7\n198.0.0.0/8\n198.51.100.7\n"),
    ['198.0.0.0/8', '198.51.100.7']);

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

$one = ['198.51.100.1'];
check('resolve: budget',
    resolveAll(['a.example', 'b.example', 'c.example', 'd.example'], $slow, 1000, $clock),
    ['addresses' => ['198.51.100.1'], 'skipped' => ['d.example'], 'failed' => [],
     'answers' => ['a.example' => $one, 'b.example' => $one, 'c.example' => $one]]);
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
    ['addresses' => ['198.51.100.1'], 'skipped' => ['c.example'], 'failed' => [],
     'answers' => ['a.example' => $one, 'b.example' => $one]]);
/* the real clock reads epoch milliseconds, so the budget must count from the
   first reading, not from the clock's zero */
$t = 1700000000000;
$cost = 400;
check('resolve: budget counts from the first reading',
    resolveAll(['a.example', 'b.example', 'c.example', 'd.example'], $slow, 1000, $clock),
    ['addresses' => ['198.51.100.1'], 'skipped' => ['d.example'], 'failed' => [],
     'answers' => ['a.example' => $one, 'b.example' => $one, 'c.example' => $one]]);
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
    ['addresses' => ['198.51.100.1'], 'skipped' => ['b.example', 'c.example'], 'failed' => [],
     'answers' => ['a.example' => $one]]);
/* a failed host does not stop the hosts after it */
$failing = ['a.example' => ['198.51.100.1'], 'b.example' => false, 'c.example' => ['198.51.100.3']];
check('resolve: failures listed',
    resolveAll(array_keys($failing), fn($h) => $failing[$h], 1000, $frozen),
    ['addresses' => ['198.51.100.1', '198.51.100.3'], 'skipped' => [], 'failed' => ['b.example'],
     'answers' => ['a.example' => $one, 'c.example' => ['198.51.100.3']]]);
check('resolve: empty answer is not a failure',
    resolveAll(['a.example'], fn($h) => [], 1000, $frozen),
    ['addresses' => [], 'skipped' => [], 'failed' => [], 'answers' => ['a.example' => []]]);
/* each name answered keeps its own addresses, sorted, unique and IPv4 only; a name
   that failed or was skipped has none */
$mixed = ['a.example' => ['198.51.100.9', '2001:db8::1', '198.51.100.7', '198.51.100.9'], 'b.example' => [],
          'c.example' => false];
check('resolve: each answered name keeps its own addresses',
    resolveAll(array_keys($mixed), fn($h) => $mixed[$h], 1000, $frozen)['answers'],
    ['a.example' => ['198.51.100.7', '198.51.100.9'], 'b.example' => []]);
check('resolve: ipv6 ignored',
    resolveAll(['a.example'], fn($h) => ['2001:db8::1', '198.51.100.2'], 1000, $frozen)['addresses'],
    ['198.51.100.2']);
check('resolve: addresses sorted',
    resolveAll(['a.example'], fn($h) => ['198.51.100.9', '198.51.100.7'], 1000, $frozen)['addresses'],
    ['198.51.100.7', '198.51.100.9']);

/* ---- answerAddresses ------------------------------------------------------- */

/* dns_get_record()'s records for one name: a sinkholed or local answer is never
   learned, and a record with no usable address is skipped */
check('answers: only the public addresses of a reply',
    answerAddresses([['ip' => '198.51.100.7'], ['ip' => '10.0.0.1'], ['ip' => '0.0.0.0'],
        ['type' => 'CNAME', 'target' => 'x.example'], ['ip' => 7], ['ip' => ['198.51.100.8']], new stdClass(),
        ['ip' => '203.0.113.9']]),
    ['198.51.100.7', '203.0.113.9']);

/* ---- messagingHosts -------------------------------------------------------- */

/* y.example is under both apps and z.example also among the notification names;
   w.example comes after both repeats */
$hostApps = ['a' => ['hosts' => ['x.example', 'y.example']],
             'b' => ['hosts' => ['y.example', 'z.example', 'w.example']]];
$notify = ['google_hosts' => ['z.example', 'g.example'], 'microsoft_hosts' => ['m.example']];
/* a slow resolver skips the end of the list, and notifications are cut whichever
   apps are selected, so their names lead */
check('hosts: the notification names, then the apps\' names, each once',
    messagingHosts($hostApps, $notify),
    ['z.example', 'g.example', 'm.example', 'x.example', 'y.example', 'w.example']);
check('hosts: with no app selected the notification names are still looked up',
    messagingHosts([], $notify),
    ['z.example', 'g.example', 'm.example']);

/* ---- updateLearned --------------------------------------------------------- */

/* protectedGuard() of the protected names: every one answered, now or recently; or
   p.example with no answer at all */
$guarded = fn(array $addresses): array => ['addresses' => $addresses, 'missing' => []];
$pausedGuard = ['addresses' => ['198.51.100.7'], 'missing' => ['p.example']];

/* The guard can change under an address learned earlier: a protected name may start
   resolving to it, or an update may widen the CDN list. Either must take it out of
   the list now, not when it expires a week later. */
check('learned: a protected name now resolving to a learned address takes it out',
    updateLearned(['198.51.100.7' => $now - $day, '198.51.100.8' => $now - $day], [],
        $guarded(['198.51.100.7']), [], $now, $maxAge),
    ['198.51.100.8' => $now - $day]);
check('learned: a wider CDN list takes a learned address out',
    updateLearned(['198.51.100.7' => $now - $day, '203.0.113.9' => $now - $day], [],
        $guarded([]), ['198.51.100.0/24'], $now, $maxAge),
    ['203.0.113.9' => $now - $day]);
/* .7 is protected and .5 a CDN address, so neither is learned; .9, known already,
   is seen again */
check('learned: this run\'s answers pass the same guard before they are learned',
    updateLearned(['203.0.113.9' => $now - $day],
        ['198.51.100.7', '192.0.2.5', '203.0.113.9', '203.0.113.10'],
        $guarded(['198.51.100.7']), ['192.0.2.0/24'], $now, $maxAge),
    ['203.0.113.10' => $now, '203.0.113.9' => $now]);
check('learned: an address not seen for the maximum age is forgotten',
    updateLearned(['203.0.113.9' => $now - $maxAge - 1], [], $guarded([]), [], $now, $maxAge),
    []);
/* A protected name with no answer may share an address with an app, and this run
   cannot tell which: learn nothing new rather than block it for a week. .9 is not
   stamped again and .10 is not learned. */
check('learned: nothing new is learned while a protected name has no answer',
    updateLearned(['203.0.113.9' => $now - $day], ['203.0.113.9', '203.0.113.10'],
        $pausedGuard, [], $now, $maxAge),
    ['203.0.113.9' => $now - $day]);
/* what the guard did answer still takes learned addresses out: .7 is protected,
   .5 a CDN address */
check('learned: with learning paused the learned set is still re-filtered',
    updateLearned(['198.51.100.7' => $now - $day, '192.0.2.5' => $now - $day, '203.0.113.9' => $now - $day], [],
        $pausedGuard, ['192.0.2.0/24'], $now, $maxAge),
    ['203.0.113.9' => $now - $day]);
check('learned: with learning paused old addresses still expire',
    updateLearned(['203.0.113.9' => $now - $maxAge - 1, '203.0.113.10' => $now - $day], [], $pausedGuard, [],
        $now, $maxAge),
    ['203.0.113.10' => $now - $day]);

/* ---- protectedGuard -------------------------------------------------------- */

$pnames = ['p.example', 'q.example'];
check('protected: every name answered',
    protectedGuard($pnames, ['p.example' => ['203.0.113.1'], 'q.example' => []], [], $now, $maxAge),
    ['addresses' => ['203.0.113.1'], 'missing' => [],
     'memory' => ['p.example' => ['addresses' => ['203.0.113.1'], 'seen' => $now],
                  'q.example' => ['addresses' => [], 'seen' => $now]]]);
/* p failed and q ran out of time this run, so neither is in the answers; a kept
   answer up to the maximum age stands in, and learning goes on; the addresses come
   out sorted and once */
$recent = ['p.example' => ['addresses' => ['203.0.113.2'], 'seen' => $now - $day],
           'q.example' => ['addresses' => ['203.0.113.1', '203.0.113.2'], 'seen' => $now - $maxAge]];
check('protected: a recent answer stands in for a failed lookup',
    protectedGuard($pnames, [], $recent, $now, $maxAge),
    ['addresses' => ['203.0.113.1', '203.0.113.2'], 'missing' => [], 'memory' => $recent]);
/* an older one does not: learning pauses, and the old answer is kept, not used */
$stale = ['q.example' => ['addresses' => ['203.0.113.2'], 'seen' => $now - $maxAge - 1]];
check('protected: a stale answer leaves the name missing',
    protectedGuard($pnames, ['p.example' => ['203.0.113.1']], $stale, $now, $maxAge),
    ['addresses' => ['203.0.113.1'], 'missing' => ['q.example'],
     'memory' => ['p.example' => ['addresses' => ['203.0.113.1'], 'seen' => $now]] + $stale]);
check('protected: a name never answered is missing',
    protectedGuard($pnames, ['p.example' => ['203.0.113.1']], [], $now, $maxAge)['missing'],
    ['q.example']);
/* an empty answer is an answer: it replaces the kept one */
check('protected: an empty answer replaces the kept one',
    protectedGuard(['p.example'], ['p.example' => []], ['p.example' => ['addresses' => ['203.0.113.1'],
        'seen' => $now - $day]], $now, $maxAge),
    ['addresses' => [], 'missing' => [], 'memory' => ['p.example' => ['addresses' => [], 'seen' => $now]]]);
check('protected: a name no longer protected is forgotten',
    protectedGuard(['p.example'], ['p.example' => ['203.0.113.1']],
        ['old.example' => ['addresses' => ['203.0.113.9'], 'seen' => $now]], $now, $maxAge)['memory'],
    ['p.example' => ['addresses' => ['203.0.113.1'], 'seen' => $now]]);

/* ---- learnedForSelection --------------------------------------------------- */

/* the learned set does not say which app an address came from, so taking an app away
   starts it again; the names still selected are looked up again in the same run */
$known = ['198.51.100.7' => $now];
check('selection: an app taken away clears the learned set',
    learnedForSelection($known, ['line', 'telegram'], ['line']), []);
check('selection: an app added keeps it',
    learnedForSelection($known, ['line'], ['line', 'telegram']), $known);
check('selection: the same apps keep it, in any order',
    learnedForSelection($known, ['line', 'telegram'], ['telegram', 'line']), $known);
check('selection: an unknown last selection keeps it',
    learnedForSelection($known, null, ['line']), $known);

/* ---- rangesDue -------------------------------------------------------------- */

$hour = 3600;
$old = ['ranges' => ['203.0.113.64/26'], 'fetched_at' => $now - $day - 1];
check('due: a list never downloaded is', rangesDue(null, $now, $day, $hour), true);
/* exactly a day old is not yet more than a day old */
check('due: a list a day old or younger is not',
    rangesDue(['ranges' => ['203.0.113.64/26'], 'fetched_at' => $now - $day], $now, $day, $hour), false);
check('due: a list more than a day old is', rangesDue($old, $now, $day, $hour), true);
check('due: a stale list that failed within the hour is not',
    rangesDue($old + ['failed_at' => $now - $hour + 1], $now, $day, $hour), false);
/* an hour after the failure it is tried again */
check('due: a stale list whose failure is an hour old is',
    rangesDue($old + ['failed_at' => $now - $hour], $now, $day, $hour), true);
/* the entry that a failed first download leaves waits the same way */
check('due: a list never downloaded waits an hour after a failure',
    [rangesDue(['ranges' => [], 'fetched_at' => 0, 'failed_at' => $now - 60], $now, $day, $hour),
     rangesDue(['ranges' => [], 'fetched_at' => 0, 'failed_at' => $now - $hour], $now, $day, $hour)],
    [false, true]);

/* ---- refreshPublished ------------------------------------------------------ */

/* A fake download that records each URL it is asked for and answers from $answers
   (false, a failed download, for any URL not in it). Each check sets both first. */
$fetched = [];
$answers = [];
$fetch = function (string $url) use (&$fetched, &$answers) {
    $fetched[] = $url;
    return $answers[$url] ?? false;
};
$url = 'https://ranges.example/cidr.txt';
$listed = ['ranges_url' => $url, 'ranges' => ['192.0.2.0/24']];
$unlisted = ['ranges_url' => null, 'ranges' => ['203.0.113.0/28']];

$fetched = [];
$answers = [$url => "198.51.100.0/24\n"];
check('published: a list never downloaded is downloaded and kept with its time',
    [refreshPublished(['t' => $listed, 'n' => $unlisted], [], $now, $day, $hour, $fetch), $fetched],
    [['ranges' => ['198.51.100.0/24', '203.0.113.0/28'],
      'published' => ['t' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => $now]],
      'failed' => []],
     [$url]]);
$fetched = [];
check('published: a list a day old is not downloaded again',
    [refreshPublished(['t' => $listed], ['t' => ['ranges' => ['203.0.113.64/26'], 'fetched_at' => $now - $day]],
        $now, $day, $hour, $fetch)['ranges'], $fetched],
    [['203.0.113.64/26'], []]);
$fetched = [];
check('published: a list more than a day old is downloaded again',
    [refreshPublished(['t' => $listed], ['t' => $old], $now, $day, $hour, $fetch), $fetched],
    [['ranges' => ['198.51.100.0/24'],
      'published' => ['t' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => $now]],
      'failed' => []],
     [$url]]);
/* the last good list and its time stay; the failure's time holds the next try back */
$answers = [];
check('published: a failed download keeps the last good list and notes the failure',
    refreshPublished(['t' => $listed], ['t' => $old], $now, $day, $hour, $fetch),
    ['ranges' => ['203.0.113.64/26'], 'published' => ['t' => $old + ['failed_at' => $now]], 'failed' => ['t']]);
$answers = [$url => "<html>\n198.51.100.0/24\n</html>"];
check('published: a download with no ranges in it is a failed download',
    refreshPublished(['t' => $listed], ['t' => $old], $now, $day, $hour, $fetch),
    ['ranges' => ['203.0.113.64/26'], 'published' => ['t' => $old + ['failed_at' => $now]], 'failed' => ['t']]);
$answers = [];
check('published: with no good download yet the shipped ranges are used',
    refreshPublished(['t' => $listed], [], $now, $day, $hour, $fetch),
    ['ranges' => ['192.0.2.0/24'], 'published' => ['t' => ['ranges' => [], 'fetched_at' => 0, 'failed_at' => $now]],
     'failed' => ['t']]);
/* within the hour after a failure nothing is downloaded, and the failure stands */
$fetched = [];
$answers = [$url => "198.51.100.0/24\n"];
check('published: a failed list is not downloaded again within the hour',
    [refreshPublished(['t' => $listed], ['t' => $old + ['failed_at' => $now - 60]], $now, $day, $hour, $fetch),
     $fetched],
    [['ranges' => ['203.0.113.64/26'], 'published' => ['t' => $old + ['failed_at' => $now - 60]], 'failed' => ['t']],
     []]);
check('published: a good download clears the failure',
    refreshPublished(['t' => $listed], ['t' => $old + ['failed_at' => $now - $hour]], $now, $day, $hour, $fetch),
    ['ranges' => ['198.51.100.0/24'], 'published' => ['t' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => $now]],
     'failed' => []]);
/* a list, and a failure, kept from a version whose data still had the app's list address */
$fetched = [];
check('published: an app with no list address uses only its shipped ranges',
    [refreshPublished(['n' => $unlisted], ['n' => $old + ['failed_at' => $now - 60]], $now, $day, $hour, $fetch),
     $fetched],
    [['ranges' => ['203.0.113.0/28'], 'published' => ['n' => $old + ['failed_at' => $now - 60]], 'failed' => []],
     []]);

/* ---- sanitizeCache --------------------------------------------------------- */

$emptyCache = ['learned' => [], 'published' => [], 'protected' => [], 'apps' => null, 'refreshed_at' => null,
               'errors' => []];
/* u failed after a good download, v before any; q answered with nothing */
$goodCache = [
    'learned' => ['198.51.100.7' => $now],
    'published' => [
        't' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => $now - $day],
        'u' => ['ranges' => ['203.0.113.0/28'], 'fetched_at' => $now - 2 * $day, 'failed_at' => $now - 60],
        'v' => ['ranges' => [], 'fetched_at' => 0, 'failed_at' => $now - 60],
    ],
    'protected' => [
        'p.example' => ['addresses' => ['203.0.113.1', '203.0.113.2'], 'seen' => $now - $day],
        'q.example' => ['addresses' => [], 'seen' => $now],
    ],
    'apps' => ['line', 'telegram'],
    'refreshed_at' => $now,
    'errors' => ['lookup failed: a.example'],
];
check('cache: a well-formed cache is kept as it is', sanitizeCache($goodCache), $goodCache);
check('cache: anything else at the top is an empty cache',
    [sanitizeCache(null), sanitizeCache('x'), sanitizeCache(new stdClass()),
     sanitizeCache(['learned' => 5, 'published' => 'x', 'protected' => 'x'])],
    [$emptyCache, $emptyCache, $emptyCache, $emptyCache]);
/* entry by entry: one bad entry costs only itself; a sinkholed or local address
   is never a learned server */
check('cache: learned entries are judged one by one',
    sanitizeCache(['learned' => ['198.51.100.7' => $now, 'not-an-ip' => $now, '198.51.100.0/24' => $now,
        '2001:db8::1' => $now, '198.51.100.8' => (string)$now, '198.51.100.9' => 1.5, '198.51.100.10' => null,
        '10.0.0.1' => $now, '0.0.0.0' => $now, '100.64.0.1' => $now]])
        ['learned'],
    ['198.51.100.7' => $now]);
check('cache: protected answers are judged one by one',
    sanitizeCache(['protected' => [
        'p.example' => ['addresses' => ['203.0.113.1'], 'seen' => $now],
        'a.example' => ['addresses' => '203.0.113.1', 'seen' => $now],
        'b.example' => ['addresses' => ['203.0.113.1', 7], 'seen' => $now],
        'c.example' => ['addresses' => ['10.0.0.1'], 'seen' => $now],
        'd.example' => ['addresses' => ['x' => '203.0.113.1'], 'seen' => $now],
        'e.example' => ['addresses' => ['203.0.113.1'], 'seen' => (string)$now],
        'f.example' => ['addresses' => ['203.0.113.1']],
        'g.example' => 'x',
        'h.example' => ['addresses' => [['203.0.113.1']], 'seen' => $now],
        'i.example' => new stdClass(),
    ]])['protected'],
    ['p.example' => ['addresses' => ['203.0.113.1'], 'seen' => $now]]);
/* an empty list is a selection of no apps; anything but a list of ids is unknown */
check('cache: apps that are not a list of ids are unknown',
    array_map(fn($apps) => sanitizeCache(['apps' => $apps])['apps'],
        [[], ['line', 7], ['a' => 'line'], 'line', null, [['line']]]),
    [[], null, null, null, null, null]);
check('cache: published entries are judged one by one',
    sanitizeCache(['published' => [
        't' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => $now],
        'a' => ['ranges' => '198.51.100.0/24', 'fetched_at' => $now],
        'b' => ['ranges' => ['198.51.100.0/24', 7], 'fetched_at' => $now],
        'c' => ['ranges' => ['bogus'], 'fetched_at' => $now],
        'd' => ['ranges' => ['x' => '198.51.100.0/24'], 'fetched_at' => $now],
        'e' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => (string)$now],
        'f' => ['ranges' => ['198.51.100.0/24']],
        'g' => 'x',
        'h' => ['ranges' => [['198.51.100.0/24']], 'fetched_at' => $now],
        'i' => new stdClass(),
        'j' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => $now, 'failed_at' => (string)$now],
    ]])['published'],
    ['t' => ['ranges' => ['198.51.100.0/24'], 'fetched_at' => $now]]);
check('cache: a refresh time or errors of the wrong type are dropped',
    [sanitizeCache(['refreshed_at' => (string)$now, 'errors' => ['a', 7, null, 'b']]),
     sanitizeCache(['refreshed_at' => $now, 'errors' => 'a'])],
    [array_merge($emptyCache, ['refreshed_at' => null, 'errors' => ['a', 'b']]),
     array_merge($emptyCache, ['refreshed_at' => $now, 'errors' => []])]);

/* ---- refreshErrors --------------------------------------------------------- */

/* the brief's wording, which status shows: lookups in the order given, failed before
   skipped for each, then the downloads */
check('errors: lookups, then downloads, as the brief words them',
    refreshErrors([['failed' => ['a.example'], 'skipped' => ['b.example']],
                   ['failed' => ['p.example'], 'skipped' => ['q.example']]], ['telegram'], []),
    ['lookup failed: a.example', 'lookup skipped (time budget): b.example',
     'lookup failed: p.example', 'lookup skipped (time budget): q.example',
     'range download failed: telegram']);
/* a pause is what matters most and status shows only the first few, so it leads */
check('errors: paused learning comes first and names every missing name',
    refreshErrors([['failed' => ['a.example'], 'skipped' => []]], [], ['p.example', 'q.example']),
    ['learning paused: no answer for p.example, q.example', 'lookup failed: a.example']);
check('errors: none when nothing went wrong',
    refreshErrors([['failed' => [], 'skipped' => []]], [], []), []);

/* ---- selectStatesToDrop ---------------------------------------------------- */

/* Rows are as `configctl filter list states` prints them (its "details"); only
   id, proto, dst_addr and dst_port are read. Ports 5228-5230 are the
   notification ports; 1, 0 is a range with no port in it. */
check('states: destination address',
    selectStatesToDrop(
        [['id' => 'a/1', 'proto' => 'tcp', 'dst_addr' => '198.51.100.7', 'dst_port' => '443']],
        ['198.51.100.7'], 5228, 5230),
    ['a/1']);
check('states: destination range',
    selectStatesToDrop(
        [['id' => 'b/1', 'proto' => 'udp', 'dst_addr' => '192.0.2.200', 'dst_port' => '3478']],
        ['192.0.2.128/25'], 5228, 5230),
    ['b/1']);
check('states: notification port',
    selectStatesToDrop(
        [['id' => 'c/1', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '5229']],
        [], 5228, 5230),
    ['c/1']);
/* the device's other connections, and anyone else's, are none of its business:
   an ordinary tcp connection, and a udp one that happens to use a notification port */
check('states: leaves unrelated',
    selectStatesToDrop(
        [['id' => 'd/1', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '443'],
         ['id' => 'd/2', 'proto' => 'udp', 'dst_addr' => '203.0.113.50', 'dst_port' => '5229']],
        ['198.51.100.0/24'], 5228, 5230),
    []);
check('states: no port range',
    selectStatesToDrop(
        [['id' => 'e/1', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '5229']],
        [], 1, 0),
    []);
check('states: malformed ignored',
    selectStatesToDrop([['id' => 'f/1']], ['198.51.100.0/24'], 5228, 5230),
    []);
/* the checks below pin rules that the ones above cannot tell apart */
check('states: port range includes both ends',
    selectStatesToDrop(
        [['id' => 'g/1', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '5227'],
         ['id' => 'g/2', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '5228'],
         ['id' => 'g/3', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '5230'],
         ['id' => 'g/4', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '5231']],
        [], 5228, 5230),
    ['g/2', 'g/3']);
/* 1, 0 is how a caller says "no ports": nothing is in it, not even the two ports it
   names (a range read the other way round, 0 to 1, would hold them) */
check('states: a reversed range holds no port',
    selectStatesToDrop(
        [['id' => 'k/0', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '0'],
         ['id' => 'k/1', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '1'],
         ['id' => 'k/2', 'proto' => 'tcp', 'dst_addr' => '203.0.113.50', 'dst_port' => '2']],
        [], 1, 0),
    []);
check('states: protocol case does not matter',
    selectStatesToDrop(
        [['id' => 'h/1', 'proto' => 'TCP', 'dst_addr' => '203.0.113.50', 'dst_port' => '5229']],
        [], 5228, 5230),
    ['h/1']);
check('states: ids unique, in input order',
    selectStatesToDrop(
        [['id' => 'i/2', 'proto' => 'tcp', 'dst_addr' => '198.51.100.7', 'dst_port' => '443'],
         ['id' => 'i/1', 'proto' => 'tcp', 'dst_addr' => '198.51.100.7', 'dst_port' => '443'],
         ['id' => 'i/2', 'proto' => 'tcp', 'dst_addr' => '198.51.100.7', 'dst_port' => '443']],
        ['198.51.100.7'], 1, 0),
    ['i/2', 'i/1']);
/* each row below would match on what it does have, so only the missing key keeps
   it out: a state that cannot be read in full is not killed on a guess */
check('states: a row missing any key is ignored',
    selectStatesToDrop(
        [['proto' => 'tcp', 'dst_addr' => '198.51.100.7', 'dst_port' => '5229'],
         ['id' => 'j/2', 'dst_addr' => '198.51.100.7', 'dst_port' => '5229'],
         ['id' => 'j/3', 'proto' => 'tcp', 'dst_port' => '5229'],
         ['id' => 'j/4', 'proto' => 'tcp', 'dst_addr' => '198.51.100.7']],
        ['198.51.100.7'], 5228, 5230),
    []);

/* ---- dropPlan -------------------------------------------------------------- */

/* what to hand selectStatesToDrop() for one device in the device table */
check('drops: a device new to the table loses every server and the notification ports',
    dropPlan('192.0.2.10', ['192.0.2.10'], ['198.51.100.0/24', '203.0.113.7'], ['203.0.113.7'], 5228, 5230),
    ['destinations' => ['198.51.100.0/24', '203.0.113.7'], 'portLo' => 5228, 'portHi' => 5230]);
check('drops: a device already there loses only the servers added this run',
    dropPlan('192.0.2.10', ['192.0.2.20'], ['198.51.100.0/24', '203.0.113.7'], ['203.0.113.7'], 5228, 5230),
    ['destinations' => ['203.0.113.7'], 'portLo' => 1, 'portHi' => 0]);
check('drops: a device already there is left alone when no server was added',
    dropPlan('192.0.2.10', ['192.0.2.20'], ['198.51.100.0/24'], [], 5228, 5230),
    null);

/* ---- stateKilled ----------------------------------------------------------- */

/* pfctl says "killed N states" for any N; anything else, an empty answer from
   configd included, did not run */
check('kill: only pfctl\'s own answer counts',
    array_map('stateKilled', ['killed 1 states for id 00000000000000a1/01', "killed 0 states\n", '',
        'pfctl: wrong id format specified', 'Execute error', 'no states killed']),
    [true, true, false, false, false, false]);

/* ---- aliasConflicts -------------------------------------------------------- */

$wanted = [MSG_DEVICES_ALIAS => MARK_MSG_DEVICES, MSG_SERVERS_ALIAS => MARK_MSG_SERVERS];

check('conflicts: foreign alias',
    aliasConflicts(
        [['name' => 'NoMessaging', 'description' => 'my own list'],
         ['name' => 'Messaging_Servers', 'description' => 'Parental Control: messaging servers']],
        $wanted),
    ['NoMessaging']);
check('conflicts: none', aliasConflicts([], $wanted), []);
/* an empty list gives an empty answer whichever way the marker is compared, so 'none'
   alone cannot tell a right comparison from an inverted one; the aliases here are all ours */
check('conflicts: our own aliases are not conflicts',
    aliasConflicts(
        [['name' => 'NoMessaging', 'description' => MARK_MSG_DEVICES],
         ['name' => 'Messaging_Servers', 'description' => MARK_MSG_SERVERS . ' (updated)']],
        $wanted),
    []);
/* setup hands over every alias in the firewall; only the two names are ours to guard */
check('conflicts: other aliases are not our business',
    aliasConflicts([['name' => 'LAN_hosts', 'description' => 'my own list']], $wanted),
    []);
check('conflicts: the marker must be a prefix',
    aliasConflicts([['name' => 'NoMessaging', 'description' => 'see ' . MARK_MSG_DEVICES]],
        $wanted),
    ['NoMessaging']);
check('conflicts: each name has its own marker',
    aliasConflicts([['name' => 'Messaging_Servers', 'description' => MARK_MSG_DEVICES]], $wanted),
    ['Messaging_Servers']);

/* ---- findForeignReferences ------------------------------------------------- */

/* The plugin's objects are own-1 (rule), own-2 (alias) and the ParentalControl
   block; user-1 is someone else's rule that uses the alias. */
$config = <<<'XML'
<opnsense>
  <OPNsense>
    <Firewall>
      <Filter>
        <rules>
          <rule uuid="user-1"><source_net>NoMessaging</source_net></rule>
          <rule uuid="own-1"><source_net>NoMessaging</source_net></rule>
        </rules>
      </Filter>
      <Alias>
        <aliases>
          <alias uuid="own-2"><name>NoMessaging</name></alias>
        </aliases>
      </Alias>
    </Firewall>
    <ParentalControl>
      <devices>
        <device uuid="dev-1"><name>NoMessaging</name></device>
      </devices>
    </ParentalControl>
  </OPNsense>
</opnsense>
XML;
check('references: user rule',
    findForeignReferences($config, ['own-1', 'own-2'], ['NoMessaging']),
    ['OPNsense/Firewall/Filter/rules/rule/source_net (user-1)']);
check('references: substring not matched',
    findForeignReferences(
        '<opnsense><OPNsense><Firewall><Alias><aliases>'
        . '<alias uuid="own-2"><name>NoMessaging</name></alias>'
        . '<alias uuid="user-2"><name>Mine</name><content>NoMessagingOld</content></alias>'
        . '</aliases></Alias></Firewall></OPNsense></opnsense>',
        ['own-1', 'own-2'], ['NoMessaging']),
    []);

/* The checks below wrap their markup in a root element and look for 'NoMessaging'
   unless told otherwise. */
$refs = fn(string $xml, array $names = ['NoMessaging'], array $own = []): array
    => findForeignReferences("<opnsense>$xml</opnsense>", $own, $names);
$inX = fn(array $texts): string
    => implode('', array_map(fn(string $t): string => "<x>$t</x>", $texts));

/* What is and is not the name: letters, digits, '_', '.' and '-' on either side
   make it part of a longer one; anything else sets it apart. */
check('references: part of a longer name is not a reference',
    $refs($inX(['OldNoMessaging', 'host.NoMessaging', 'old-NoMessaging',
                'NoMessagingOld', 'NoMessaging.example', 'NoMessaging-old', 'NoMessaging_Kids'])),
    []);
/* five elements, five locations: every element is reported, none merged */
check('references: set off by other characters is one',
    $refs($inX(['NoMessaging', 'a,NoMessaging,b', "a\nNoMessaging\nb", 'block NoMessaging now',
                '(NoMessaging)'])),
    ['x', 'x', 'x', 'x', 'x']);

/* only that one subtree is the plugin's: a neighbour with a longer name, and a
   ParentalControl element anywhere else, are foreign */
check('references: only the plugin\'s own subtree is skipped',
    $refs('<OPNsense><ParentalControl2><a>NoMessaging</a></ParentalControl2></OPNsense>'
        . '<x><OPNsense><ParentalControl><b>NoMessaging</b></ParentalControl></OPNsense></x>'),
    ['OPNsense/ParentalControl2/a', 'x/OPNsense/ParentalControl/b']);

/* Where the hit is reported: the path below the root, then the nearest uuid. */
check('references: an element without a uuid is named by its path alone',
    $refs('<filter><rule><source><address>NoMessaging</address></source></rule></filter>'),
    ['filter/rule/source/address']);
check('references: an attribute counts, and so does the element\'s own uuid',
    $refs('<alias uuid="user-3" descr="NoMessaging"/>'),
    ['alias (user-3)']);
check('references: the nearest uuid names the location',
    $refs('<rule uuid="user-1"><sub uuid="user-2"><x>NoMessaging</x></sub></rule>'),
    ['rule/sub/x (user-2)']);
check('references: every name is looked for',
    $refs('<a>Messaging_Servers</a>', ['NoMessaging', 'Messaging_Servers']),
    ['a']);
/* a CDATA section is text like any other */
check('references: CDATA text counts',
    $refs('<a><![CDATA[NoMessaging]]></a>'),
    ['a']);
/* a name is matched as written, never as a pattern: '.' is not "any character" */
check('references: a name is matched literally',
    $refs('<a>axb</a><b>a.b</b>', ['a.b']),
    ['b']);
/* an empty uuid in the list of the plugin's own must not hide elements that have none */
check('references: an empty own uuid hides nothing',
    $refs('<a>NoMessaging</a>', ['NoMessaging'], ['']),
    ['a']);
/* a clean uninstall of a plugin that never made an alias has no names; the indented
   text is there because a pattern built from no names would match whitespace */
check('references: no names, nothing to find',
    $refs("\n  <a>\n    <b>x</b>\n  </a>\n", []),
    []);
/* A match that fails to run is not a miss: text the pattern could not judge counts as a
   hit, so uninstall refuses. The failure is forced with a backtrack limit of 1 and no
   JIT, on a name no other check compiles; 'PcreProbeX' is no reference to it. */
$jit = (string)ini_get('pcre.jit');
$limit = (string)ini_get('pcre.backtrack_limit');
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '1');
$unjudged = $refs('<a>PcreProbeX</a>', ['PcreProbe']);
ini_set('pcre.jit', $jit);
ini_set('pcre.backtrack_limit', $limit);
check('references: text the regex cannot judge counts as a hit',
    [$unjudged, $refs('<a>PcreProbeX</a>', ['PcreProbe'])],
    [['a'], []]);

/* A config that cannot be read must make uninstall refuse, not crash it and not
   wave it through: an exception, a warning (a caller's error handler may turn it
   into an exception) or an empty answer would each do one of those. An empty string
   is the sharp one: DOMDocument throws on it. */
$warnings = [];
set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;
    return true;
});
$answers = [];
foreach (['', "  \n", 'not xml', '<opnsense><filter>', "<opnsense>\0</opnsense>"] as $damaged) {
    try {
        $answers[] = findForeignReferences($damaged, [], ['NoMessaging']);
    } catch (Throwable $e) {
        $answers[] = get_class($e) . ': ' . $e->getMessage();
    }
}
restore_error_handler();
check('references: unparseable config refuses',
    $answers,
    array_fill(0, 5, ['config.xml could not be parsed']));
check('references: unparseable config raises no warning', $warnings, []);
/* the parser's error mode belongs to the caller: leave it as it was found */
$modes = [];
foreach ([false, true] as $before) {
    libxml_use_internal_errors($before);
    findForeignReferences('<opnsense><filter>', [], ['NoMessaging']);
    $modes[] = libxml_use_internal_errors();
}
libxml_use_internal_errors(false);
check('references: leaves the libxml error mode as it found it', $modes, [false, true]);

finish();
