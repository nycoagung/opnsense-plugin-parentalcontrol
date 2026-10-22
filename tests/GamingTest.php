#!/usr/local/bin/php
<?php
/**
 * Table-driven tests for the gaming block's pure functions: the per-device decision,
 * the port lists the rules use, the destinations never treated as gaming, which DNS
 * answers may be learned, and which open connections a new block drops.
 *
 * Runs anywhere PHP does - no OPNsense, no firewall, no network. A wrong answer here
 * is silent: a child keeps playing, or a call, Wi-Fi Calling or the local network
 * stops working on a device that is only meant to lose its games.
 *
 *   php tests/GamingTest.php
 */

$lib = __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/lib';
require $lib . '/markers.php';
require $lib . '/messaging.php';
require $lib . '/gaming.php';

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

/* ---- the decision ---------------------------------------------------------- */

$outside = [true, 'outside allowed hours'];
$within = [false, 'within allowed hours'];
check('decision: plugin disabled', gamingDecision(false, true, 'on', $outside), [false, 'plugin disabled']);
check('decision: device disabled', gamingDecision(true, false, 'on', $outside), [false, 'device disabled']);
check('decision: off', gamingDecision(true, true, 'off', $outside), [false, 'gaming allowed']);
check('decision: on', gamingDecision(true, true, 'on', $within), [true, 'gaming always blocked']);
check('decision: schedule follows the schedule',
    [gamingDecision(true, true, 'schedule', $outside), gamingDecision(true, true, 'schedule', $within)],
    [[true, 'gaming: outside allowed hours'], [false, 'gaming: within allowed hours']]);
check('decision: an unknown setting blocks', gamingDecision(true, true, 'bogus', $within),
    [true, 'unknown gaming setting']);
check('decision: messaging reasons unchanged',
    [messagingDecision(true, true, 'off', $outside), messagingDecision(true, true, 'on', $within),
     messagingDecision(true, true, 'schedule', $within), messagingDecision(true, true, 'bogus', $within)],
    [[false, 'messaging allowed'], [true, 'messaging always blocked'], [false, 'messaging: within allowed hours'],
     [true, 'unknown messaging setting']]);

/* ---- ports ----------------------------------------------------------------- */

$safe = [[53, 53], [123, 123], [443, 443], [500, 500], [3478, 3497], [4500, 4500], [8801, 8810], [10000, 10000],
    [16384, 16387], [16393, 16402], [19302, 19309]];
check('ports: the shipped safe-list\'s complement', complementPorts($safe),
    [[1, 52], [54, 122], [124, 442], [444, 499], [501, 3477], [3498, 4499], [4501, 8800], [8811, 9999],
     [10001, 16383], [16388, 16392], [16403, 19301], [19310, 65535]]);
/* each port 1-65535 in exactly one of the two lists: the UDP rule then blocks every
   port the safe-list does not hold, and none it does */
$covered = array_fill(1, 65535, 0);
foreach (array_merge(complementPorts($safe), $safe) as [$lo, $hi]) {
    for ($p = $lo; $p <= $hi; $p++) {
        $covered[$p]++;
    }
}
check('ports: complement and safe-list cover 1-65535 once each',
    [count($covered), array_keys(array_filter($covered, fn(int $n): bool => $n !== 1))], [65535, []]);
check('ports: no safe-list gives [[1,65535]]', complementPorts([]), [[1, 65535]]);
check('ports: [[1,1]] gives [[2,65535]]', complementPorts([[1, 1]]), [[2, 65535]]);
check('ports: [[65535,65535]] gives [[1,65534]]', complementPorts([[65535, 65535]]), [[1, 65534]]);
check('ports: [[1,65534]] gives [[65535,65535]]', complementPorts([[1, 65534]]), [[65535, 65535]]);
check('ports: overlapping input is merged', complementPorts([[10, 20], [15, 30], [5, 5], [31, 31]]),
    [[1, 4], [6, 9], [32, 65535]]);
check('alias content: [[53,53],[100,200]] is "53\n100:200"', portAliasContent([[53, 53], [100, 200]]),
    "53\n100:200");

/* ---- the firewall's own addresses ------------------------------------------ */

$ifconfig = <<<'IFCONFIG'
igb0: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1500
	inet 198.51.100.7 netmask 0xffffff00 broadcast 198.51.100.255
	inet6 fe80::1%igb0 prefixlen 64 scopeid 0x1
igb1: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1500
	inet 192.0.2.1 netmask 0xffffff00 broadcast 192.0.2.255
	inet6 2001:db8::1 prefixlen 64
igb1_vlan20: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1500
	inet 192.0.2.1 netmask 0xffffffff broadcast 192.0.2.1
lo0: flags=8049<UP,LOOPBACK,RUNNING,MULTICAST> metric 0 mtu 16384
	inet 127.0.0.1 netmask 0xff000000
	inet6 ::1 prefixlen 128
IFCONFIG;
check('firewall addresses: inet lines only, loopback out, sorted', firewallIpv4($ifconfig),
    ['192.0.2.1', '198.51.100.7']);
check('firewall addresses: empty output gives []', firewallIpv4(''), []);

/* ---- never blocked --------------------------------------------------------- */

$gaming = ['never_blocked' => ['discord' => ['198.51.100.0/24']]];
$messaging = ['apps' => ['a' => ['ranges' => ['203.0.113.0/25'], 'extra_ranges' => ['203.0.113.128/26'],
    'site_ranges' => ['203.0.113.200/32']]]];
check('never blocked: local set, own addresses, never_blocked lists and messaging ranges, canonical and sorted',
    neverBlocked(['192.0.2.1'], $gaming, $messaging),
    ['10.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.2.1', '192.168.0.0/16', '198.51.100.0/24',
     '203.0.113.0/25', '203.0.113.128/26', '203.0.113.200', '224.0.0.0/4', '255.255.255.255']);
check('never blocked: without messaging data the rest is kept',
    neverBlocked(['192.0.2.1'], $gaming, null),
    ['10.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.2.1', '192.168.0.0/16', '198.51.100.0/24',
     '224.0.0.0/4', '255.255.255.255']);

/* ---- exempt ready ---------------------------------------------------------- */

$local = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '224.0.0.0/4', '255.255.255.255'];
check('exempt ready: all of GAME_LOCAL listed', exemptReady(array_merge(['198.51.100.0/24'], $local)), true);
check('exempt ready: one missing', exemptReady(array_slice($local, 1)), false);
check('exempt ready: empty table', exemptReady([]), false);

/* ---- refusal ----------------------------------------------------------------- */

/* Gaming_Exempt renamed by hand: core repoints the rules to the new name, whose
   table starts out empty, while pf keeps the old table, full, under the fixed name.
   That full table must not let a device in, or the UDP rule blocks its LAN. */
check('refusal: Gaming_Exempt renamed, the old table still full',
    gamingRefusal('gaming alias renamed: Exempt2 should be Gaming_Exempt', $local),
    'gaming alias renamed: Exempt2 should be Gaming_Exempt; no device blocked this run');
check('refusal: objects as made, the local set present', gamingRefusal(null, $local), null);
check('refusal: objects as made, the local set incomplete', gamingRefusal(null, array_slice($local, 1)),
    'never-blocked table incomplete; no device blocked this run');

/* ---- answers --------------------------------------------------------------- */

$a = fn(string $host, string $ip): array => ['host' => $host, 'class' => 'IN', 'ttl' => 60, 'type' => 'A', 'ip' => $ip];
$elb = ['.elb.amazonaws.com'];
check('answers: plain A records kept',
    plainAnswers([$a('game.example', '203.0.113.5'), $a('game.example', '203.0.113.6')], 'game.example', $elb),
    ['203.0.113.5', '203.0.113.6']);
check('answers: alias to an own load balancer kept',
    plainAnswers([$a('x-1.us-east-2.elb.amazonaws.com', '203.0.113.7')], 'login.game.example', $elb),
    ['203.0.113.7']);
check('answers: alias to a CDN dropped',
    plainAnswers([$a('d1.cloudfront.net', '203.0.113.8')], 'www.game.example', $elb), []);
check('answers: case and trailing dot ignored',
    plainAnswers([$a('Game.Example.', '203.0.113.9')], 'game.example', $elb), ['203.0.113.9']);
/* a resolver that sinkholes a game's name answers 0.0.0.0 or a local block page;
   blocking that would cut a device off a host on its own network */
check('answers: a sinkhole answer is dropped',
    plainAnswers([$a('game.example', '0.0.0.0'), $a('game.example', '192.168.1.2'),
                  $a('game.example', '203.0.113.5')], 'game.example', $elb),
    ['203.0.113.5']);

/* ---- the game-servers table ------------------------------------------------ */

check('destinations: learned plus ranges, unique, sorted',
    gamingDestinations(['203.0.113.9' => 1, '198.51.100.7' => 2],
        ['a' => ['label' => 'A', 'hosts' => ['a.example']], 'b' => ['label' => 'B', 'ranges' => ['203.0.113.0/28',
        '192.0.2.0/24']], 'c' => ['label' => 'C', 'ranges' => ['203.0.113.0/28']]]),
    ['192.0.2.0/24', '198.51.100.7', '203.0.113.0/28', '203.0.113.9']);
check('destinations: a server without ranges adds nothing',
    gamingDestinations([], ['a' => ['label' => 'A', 'hosts' => ['a.example']]]), []);
/* a proxy host's address sits in a shared CDN's range, so it never enters the learned
   set: it is added by name, from its own remembered answer */
check('destinations: proxy hosts\' addresses added',
    gamingDestinations(['203.0.113.9' => 1], [], ['198.51.100.7', '203.0.113.9']),
    ['198.51.100.7', '203.0.113.9']);
/* the refresh hands it the game list's entries (gameEntry()), keyed by uuid */
check('destinations: the ranges of the game entries given',
    gamingDestinations(['203.0.113.9' => 1], ['u1' => ['name' => 'G', 'enabled' => true, 'hosts' => [],
        'proxy_hosts' => [], 'ranges' => ['198.51.100.0/24']]]),
    ['198.51.100.0/24', '203.0.113.9']);

/* ---- hosts ----------------------------------------------------------------- */

$hostServers = [
    'a' => ['label' => 'A', 'hosts' => ['a.example', 'b.example'], 'proxy_hosts' => ['p.example']],
    'b' => ['label' => 'B', 'hosts' => ['b.example', 7], 'ranges' => ['192.0.2.0/24']],
];
check('hosts: the plain hosts of every game, each once', gameHosts($hostServers, 'hosts'), ['a.example', 'b.example']);
check('hosts: the proxy hosts kept apart', gameHosts($hostServers, 'proxy_hosts'), ['p.example']);
/* A proxy host is looked up with no name of its own (''): only an answer through a
   proxy suffix counts, so if the name becomes an ordinary proxied record (its own A
   record) or a shared CDN alias, nothing is blocked. Still only public answers. */
$pa = ['.pacloudflare.example'];
check('answers: a proxy host\'s answer through its proxy alias counts, if public',
    gameAnswers([$a('x.pacloudflare.example', '203.0.113.8'), $a('x.pacloudflare.example', '10.0.0.8')],
        'gate.game.example', true, $elb, $pa),
    ['203.0.113.8']);
check('answers: a proxy host answering with its own record counts for nothing',
    gameAnswers([$a('gate.game.example', '203.0.113.8')], 'gate.game.example', true, $elb, $pa), []);
check('answers: a proxy host answering through a shared CDN alias counts for nothing',
    gameAnswers([$a('gate.game.example.cdn.cloudflare.example', '203.0.113.8')], 'gate.game.example', true, $elb, $pa),
    []);
check('answers: a plain host keeps its own record, and not a proxy alias',
    gameAnswers([$a('game.example', '203.0.113.5'), $a('x.pacloudflare.example', '203.0.113.6')], 'game.example',
        false, $elb, $pa),
    ['203.0.113.5']);

/* ---- drop plan ------------------------------------------------------------- */

check('drop plan: newly blocked device gets ports and all servers',
    gamingDropPlan('192.0.2.10', ['192.0.2.10'], ['198.51.100.1'], []),
    ['ports' => true, 'servers' => ['198.51.100.1']]);
check('drop plan: already blocked device gets only new servers',
    gamingDropPlan('192.0.2.10', [], ['198.51.100.1', '198.51.100.2'], ['198.51.100.2']),
    ['ports' => false, 'servers' => ['198.51.100.2']]);
check('drop plan: nothing new gives null', gamingDropPlan('192.0.2.10', [], ['198.51.100.1'], []), null);

/* ---- states ---------------------------------------------------------------- */

$row = fn(string $id, string $proto, string $addr, string $port): array =>
    ['id' => $id, 'proto' => $proto, 'dst_addr' => $addr, 'dst_port' => $port];
$exempt = ['10.0.0.0/8', '192.168.0.0/16', '198.51.100.0/24'];
$tcp = [[9339, 9339]];
$servers = ['203.0.113.50'];
$pick = fn(array $rows, bool $ports = true): array => selectGamingStates($rows, $exempt, $safe, $tcp, $servers, $ports);
check('states: UDP to a blocked port goes', $pick([$row('1/a', 'udp', '203.0.113.5', '7777')]), ['1/a']);
check('states: UDP 443 stays', $pick([$row('2/a', 'udp', '203.0.113.5', '443')]), []);
check('states: UDP to a never-blocked network stays',
    $pick([$row('3/a', 'udp', '198.51.100.9', '7777'), $row('7/a', 'udp', '192.168.1.5', '7777')]), []);
check('states: TCP 9339 goes', $pick([$row('4/a', 'tcp', '203.0.113.9', '9339')]), ['4/a']);
check('states: TCP 443 stays', $pick([$row('5/a', 'tcp', '203.0.113.9', '443')]), []);
check('states: anything to a server goes', $pick([$row('6/a', 'tcp', '203.0.113.50', '443')]), ['6/a']);
$all = [$row('1/a', 'udp', '203.0.113.5', '7777'), $row('2/a', 'udp', '203.0.113.5', '443'),
        $row('4/a', 'tcp', '203.0.113.9', '9339'), $row('6/a', 'udp', '203.0.113.50', '443'),
        $row('6/a', 'tcp', '203.0.113.50', '443')];
check('states: with ports=false only server states go', $pick($all, false), ['6/a']);
check('states: incomplete rows are ignored',
    $pick([['id' => '8/a', 'proto' => 'udp', 'dst_addr' => '203.0.113.5'], ['proto' => 'udp',
        'dst_addr' => '203.0.113.5', 'dst_port' => '7777']]), []);

/* ---- markers and names ----------------------------------------------------- */

$markers = array_filter(get_defined_constants(true)['user'],
    fn(string $name): bool => str_starts_with($name, 'MARK_'), ARRAY_FILTER_USE_KEY);
$prefixes = [];
foreach ($markers as $name => $marker) {
    foreach ($markers as $other => $longer) {
        if ($name !== $other && str_starts_with($longer, $marker)) {
            $prefixes[] = "$name is a prefix of $other";
        }
    }
}
check('markers: no marker is a prefix of another', [count($markers) >= 15, $prefixes], [true, []]);
check('names: every gaming table name is at most 31 characters',
    array_keys(array_filter(GAME_ALIASES, fn(string $marker, string $name): bool => strlen($name) > 31,
        ARRAY_FILTER_USE_BOTH)), []);
check('conflicts: aliasConflicts() reports a foreign NoGaming and Gaming_Exempt, not our own',
    aliasConflicts([['name' => 'NoGaming', 'description' => 'my own list'],
                    ['name' => 'Gaming_Exempt', 'description' => ''],
                    ['name' => 'Gaming_Servers', 'description' => MARK_GAME_SERVERS],
                    ['name' => 'Other', 'description' => 'x']], GAME_ALIASES),
    ['NoGaming', 'Gaming_Exempt']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
