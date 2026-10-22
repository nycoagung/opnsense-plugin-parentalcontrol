#!/usr/local/bin/php
<?php
/**
 * Table-driven tests for the game list's rules: a game's entry as the settings hold
 * it, which shipped games join the list, the checks a game must pass to be saved,
 * the ranges no game may block, what each host is remembered to give, what each
 * game blocks and why, and the gaming cache.
 *
 * Runs anywhere PHP does - no OPNsense, no firewall, no network. A wrong answer here
 * is silent: a game the parent switched off stays blocked, a shipped game joins the
 * list twice, a saved game blocks a shared service for every device denied gaming,
 * or the Games list shows a game blocking what it does not.
 *
 *   php tests/GamesTest.php
 */

$lib = __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/lib';
require $lib . '/markers.php';
require $lib . '/messaging.php';
require $lib . '/gaming.php';
require $lib . '/games.php';

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

/* one game as the settings hold it */
function row(string $name, string $hosts, string $proxyHosts = '', string $ranges = '', string $enabled = '1'): array
{
    return gameEntry(['enabled' => $enabled, 'name' => $name, 'hosts' => $hosts, 'proxy_hosts' => $proxyHosts,
        'ranges' => $ranges]);
}

/* ---- entries --------------------------------------------------------------- */

check('entry: lists split, trimmed, lower-cased, without blanks or repeats',
    gameEntry(['enabled' => '1', 'name' => 'Game', 'hosts' => 'A.example, ,b.example,a.example', 'proxy_hosts' => '',
        'ranges' => '192.0.2.0/24']),
    ['name' => 'Game', 'enabled' => true, 'hosts' => ['a.example', 'b.example'], 'proxy_hosts' => [],
        'ranges' => ['192.0.2.0/24']]);
check('entry: a switched-off game', row('Game', 'a.example', '', '', '0')['enabled'], false);
/* the settings take a name with a trailing dot, as dig prints it; DNS ignores the dot */
check('entry: a host name loses its trailing dot, as DNS compares it',
    [row('G', 'A.Example.,b.example', 'p.example.')['hosts'], row('G', 'A.Example.,b.example', 'p.example.')['proxy_hosts']],
    [['a.example', 'b.example'], ['p.example']]);
check('shipped: the label names it, switched on',
    shippedEntry(['label' => 'G', 'hosts' => ['h.example'], 'proxy_hosts' => ['p.example']]),
    ['name' => 'G', 'enabled' => true, 'hosts' => ['h.example'], 'proxy_hosts' => ['p.example'], 'ranges' => []]);

/* ---- which shipped games join the list ------------------------------------- */

$two = ['a' => ['label' => 'Game A', 'hosts' => ['a.example']], 'b' => ['label' => 'Game B', 'hosts' => ['b.example']]];
$added = gamesToAdd($two, [], []);
check('add: an empty list takes every shipped game, in order, and holds every key',
    [array_keys($added['add']), $added['clashes'], $added['seen']], [['a', 'b'], [], ['a', 'b']]);
$added = gamesToAdd($two, ['a'], []);
check('add: a held key is not added again, deleted or not',
    [array_keys($added['add']), $added['seen']], [['b'], ['a', 'b']]);
$added = gamesToAdd($two, ['a', 'b'], []);
check('add: nothing new changes nothing', [$added['add'], $added['seen']], [[], ['a', 'b']]);
$added = gamesToAdd($two, [], [row('GAME A', 'x.example')]);
check('add: a game the list already has by name, any case, is held but not added',
    [array_keys($added['add']), $added['clashes'], $added['seen']],
    [['b'], ['Game A not added: the list already has a game named GAME A'], ['a', 'b']]);
$added = gamesToAdd($two, [], [row('Mine', 'b.example')]);
check('add: a host already listed keeps a shipped game out',
    [$added['clashes'], array_keys($added['add'])], [['Game B not added: the list already has b.example'], ['a']]);

/* ---- the checks a game must pass to be saved ------------------------------- */

$mine = row('Mine', 'm.example');
$other = row('Other', 'b.example');
$domains = ['web.app'];
$guarded = ['198.51.100.0/24'];
check('problems: a fine entry has none', gameProblems($mine, [$other], $domains, $guarded), []);
check('problems: a name another game has, in any case',
    gameProblems($mine, [row('MINE', 'z.example')], $domains, $guarded),
    ['name' => 'Another game already has this name.']);
check('problems: nothing to block', gameProblems(row('Mine', ''), [$other], $domains, $guarded),
    ['hosts' => 'Add at least one host, proxy host or address range.']);
check('problems: a host another game lists', gameProblems(row('Mine', 'b.example'), [$other], $domains, $guarded),
    ['hosts' => 'b.example is already listed for Other.']);
check('problems: shared hosting, the domain itself and below it',
    [gameProblems(row('Mine', 'x.web.app'), [], $domains, $guarded),
     gameProblems(row('Mine', 'web.app'), [], $domains, $guarded),
     gameProblems(row('Mine', 'notweb.app'), [], $domains, $guarded)],
    [['hosts' => 'x.web.app is on a shared hosting service, so blocking it would block other sites too.'],
     ['hosts' => 'web.app is on a shared hosting service, so blocking it would block other sites too.'],
     []]);
check('problems: a proxy host is checked the same way',
    [gameProblems(row('Mine', 'm.example', 'x.web.app'), [], $domains, $guarded),
     gameProblems(row('Mine', 'm.example', 'b.example'), [$other], $domains, $guarded)],
    [['proxy_hosts' => 'x.web.app is on a shared hosting service, so blocking it would block other sites too.'],
     ['proxy_hosts' => 'b.example is already listed for Other.']]);
check('problems: a trailing dot hides neither a shared hosting service nor a host another game lists',
    [gameProblems(row('Mine', 'x.web.app.'), [], $domains, $guarded),
     gameProblems(row('Mine', 'b.example.'), [$other], $domains, $guarded)],
    [['hosts' => 'x.web.app is on a shared hosting service, so blocking it would block other sites too.'],
     ['hosts' => 'b.example is already listed for Other.']]);
check('problems: a proxy host also listed as one of the game\'s own hosts',
    gameProblems(row('Mine', 'm.example', 'm.example'), [], $domains, $guarded),
    ['proxy_hosts' => 'm.example is already listed for Mine.']);
check('problems: a private or special-purpose range',
    gameProblems(row('Mine', 'm.example', '', '10.0.0.0/8'), [], $domains, $guarded),
    ['ranges' => '10.0.0.0/8 is not a public IPv4 network.']);
check('problems: a range wider than a /8, which the table refuses; a /8 passes',
    [gameProblems(row('Mine', 'm.example', '', '198.0.0.0/7'), [], $domains, []),
     gameProblems(row('Mine', 'm.example', '', '198.0.0.0/8'), [], $domains, [])],
    [['ranges' => '198.0.0.0/7 is wider than a /8, which no single service owns.'], []]);
check('problems: a range overlapping a guarded one, either way',
    [gameProblems(row('Mine', 'm.example', '', '198.51.100.0/25'), [], $domains, $guarded),
     gameProblems(row('Mine', 'm.example', '', '198.51.0.0/16'), [], $domains, $guarded)],
    [['ranges' => '198.51.100.0/25 overlaps a shared service that is never blocked.'],
     ['ranges' => '198.51.0.0/16 overlaps a shared service that is never blocked.']]);

/* ---- the ranges no game may block ------------------------------------------ */

$frontEnds = ['shared_front_end_ranges' => ['x' => ['203.0.113.0/24'], 'y' => ['192.0.2.0/24']]];
check('guards: CDN and front-end ranges together, once, sorted',
    guardedRanges($frontEnds, ['shared_cdn_ranges' => ['198.51.100.0/24', '192.0.2.0/24']]),
    ['192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24']);
check('guards: without messaging data, the front ends alone', guardedRanges($frontEnds, null),
    ['192.0.2.0/24', '203.0.113.0/24']);

/* ---- the shipped games from gaming.json ------------------------------------ */

$files = [];
foreach (['valid' => ['schema' => 1, 'servers' => ['a' => ['label' => 'A']]], 'schema 2' => ['schema' => 2,
    'servers' => []], 'no servers' => ['schema' => 1]] as $what => $content) {
    $files[$what] = (string)tempnam(sys_get_temp_dir(), 'games');
    file_put_contents($files[$what], json_encode($content));
}
check('shipped servers: a schema-1 file\'s servers; anything else is null',
    [shippedServers($files['valid']), shippedServers($files['schema 2']), shippedServers($files['no servers']),
     shippedServers(sys_get_temp_dir() . '/no-such-games-file.json')],
    [['a' => ['label' => 'A']], null, null, null]);
foreach ($files as $file) {
    unlink($file);
}

/* ---- each host's remembered addresses -------------------------------------- */

$now = 1000;
$max = 100;
check('learn: a fresh answer is stamped and kept with the host\'s earlier ones',
    learnHosts(['h.example' => ['192.0.2.1' => 950]], ['h.example' => ['192.0.2.2']], ['h.example'], [], [], [],
        $now, $max),
    ['h.example' => ['192.0.2.1' => 950, '192.0.2.2' => 1000]]);
check('learn: a host off the list loses its addresses at once',
    learnHosts(['h.example' => ['192.0.2.1' => 950]], [], [], [], [], [], $now, $max), []);
check('learn: a host with no answer this run keeps its recent addresses',
    learnHosts(['h.example' => ['192.0.2.1' => 950, '192.0.2.2' => 990]], [], ['h.example'], [], [], [], $now, $max),
    ['h.example' => ['192.0.2.1' => 950, '192.0.2.2' => 990]]);
check('learn: addresses older than the maximum age go',
    learnHosts(['h.example' => ['192.0.2.1' => 899, '192.0.2.2' => 900]], [], ['h.example'], [], [], [], $now, $max),
    ['h.example' => ['192.0.2.2' => 900]]);
$mixed = ['h.example' => ['192.0.2.1' => 950, '203.0.113.9' => 950]];
$fresh = ['k.example' => ['192.0.2.2']];
$both = ['h.example', 'k.example'];
check('learn: a protected, never-blocked or shared address goes, whichever host gave it',
    [learnHosts($mixed, $fresh, $both, ['192.0.2.1', '192.0.2.2'], [], [], $now, $max),
     learnHosts($mixed, $fresh, $both, [], [], ['192.0.2.0/24'], $now, $max),
     learnHosts($mixed, $fresh, $both, [], ['192.0.2.0/24'], [], $now, $max)],
    array_fill(0, 3, ['h.example' => ['203.0.113.9' => 950]]));
check('learn: hosts and addresses come out sorted',
    learnHosts(['k.example' => ['203.0.113.9' => 950, '192.0.2.1' => 950]], ['a.example' => ['198.51.100.7']],
        ['k.example', 'a.example'], [], [], [], $now, $max),
    ['a.example' => ['198.51.100.7' => 1000], 'k.example' => ['192.0.2.1' => 950, '203.0.113.9' => 950]]);
check('learned: every address once, with its latest time',
    learnedAddresses(['a.example' => ['203.0.113.9' => 960, '192.0.2.1' => 990], 'b.example' => ['192.0.2.1' => 950]]),
    ['192.0.2.1' => 990, '203.0.113.9' => 960]);

/* ---- the guard every answer passes ----------------------------------------- */

$cloud = ['198.51.100.0/24'];
check('guard: a proxy answer in a shared range stays blockable; protected or never-blocked ones do not',
    [blockable(['198.51.100.7'], [], $cloud, [], true), blockable(['198.51.100.7'], [], $cloud, [], false),
     blockable(['198.51.100.7'], ['198.51.100.7'], $cloud, [], true),
     blockable(['198.51.100.7'], ['198.51.100.7'], $cloud, [], false),
     blockable(['198.51.100.7'], [], $cloud, $cloud, true), blockable(['198.51.100.7'], [], $cloud, $cloud, false)],
    [['198.51.100.7'], [], [], [], [], []]);
check('guard: what stays comes out sorted, each once',
    blockable(['203.0.113.9', '192.0.2.1', '203.0.113.9'], [], [], [], false), ['192.0.2.1', '203.0.113.9']);

/* ---- what each game blocks ------------------------------------------------- */

$memory = ['h.example' => ['192.0.2.1' => 990, '192.0.2.2' => 990],
    'k.example' => ['192.0.2.2' => 990, '192.0.2.3' => 990]];
check('outcomes: switched off',
    gameOutcomes(['u1' => row('G', 'h.example', '', '', '0')], $memory, [], [], ['192.0.2.1', '192.0.2.2']),
    ['u1' => ['addresses' => 0, 'ranges' => 0, 'why' => 'switched off']]);
check('outcomes: counts the addresses and ranges in the table',
    gameOutcomes(['u1' => row('G', 'h.example, k.example', 'p.example', '198.51.100.0/24, 203.0.113.0/25')], $memory,
        ['p.example' => ['addresses' => ['192.0.2.9'], 'seen' => 990]], [],
        ['192.0.2.1', '192.0.2.2', '192.0.2.9', '198.51.100.0/24']),
    ['u1' => ['addresses' => 3, 'ranges' => 1, 'why' => null]]);
check('outcomes: a range typed with host bits counts as the network the table holds',
    gameOutcomes(['u1' => row('G', '', '', '198.51.100.9/24')], [], [], [], ['198.51.100.0/24']),
    ['u1' => ['addresses' => 0, 'ranges' => 1, 'why' => null]]);
$ab = learnHosts([], ['a.example' => ['192.0.2.1'], 'b.example' => ['192.0.2.1']], ['a.example', 'b.example'], [],
    [], [], $now, $max);
$bOnly = learnHosts($ab, [], ['b.example'], [], [], [], $now + 60, $max);
check('outcomes: an address two games share counts for both, and stays while one still gives it',
    [gameOutcomes(['ua' => row('A', 'a.example'), 'ub' => row('B', 'b.example')], $ab, [], [],
        array_keys(learnedAddresses($ab))),
     learnedAddresses($bOnly),
     gameOutcomes(['ua' => row('A', 'a.example', '', '', '0'), 'ub' => row('B', 'b.example')], $bOnly, [], [],
        array_keys(learnedAddresses($bOnly)))],
    [['ua' => ['addresses' => 1, 'ranges' => 0, 'why' => null], 'ub' => ['addresses' => 1, 'ranges' => 0, 'why' => null]],
     ['192.0.2.1' => 1000],
     ['ua' => ['addresses' => 0, 'ranges' => 0, 'why' => 'switched off'],
      'ub' => ['addresses' => 1, 'ranges' => 0, 'why' => null]]]);
$cdn = 'answers through a CDN or shared front end';
$why = ['g.example' => 'every answer is guarded', 'c.example' => $cdn, 't.example' => 'not looked up in time',
    'n.example' => 'no answer'];
$whyOf = fn(string $hosts, string $proxyHosts = ''): ?string
    => gameOutcomes(['u1' => row('G', $hosts, $proxyHosts)], [], [], $why, [])['u1']['why'];
check('outcomes: why a game blocks nothing, host by host',
    [$whyOf('g.example'), $whyOf('c.example'), $whyOf('t.example'), $whyOf('n.example'), $whyOf('u.example'),
     $whyOf('', 't.example')],
    ['every answer is guarded', $cdn, 'not looked up in time', 'no answer', 'no answer', 'not looked up in time']);
check('outcomes: why, when the hosts differ: guarded, then CDN, then time, then no answer',
    [$whyOf('n.example, t.example, c.example, g.example'), $whyOf('n.example, t.example, c.example'),
     $whyOf('n.example, t.example')],
    ['every answer is guarded', $cdn, 'not looked up in time']);

/* ---- why a host gave nothing to block -------------------------------------- */

$own = ['host' => 'h.example', 'type' => 'A', 'ip' => '198.51.100.7'];
$other = ['host' => 'h.example', 'type' => 'A', 'ip' => '203.0.113.9'];
check('reason: not looked up in time', hostReason(null, [], [], [], []), 'not looked up in time');
check('reason: the lookup failed', hostReason(false, [], [], [], []), 'no answer');
check('reason: an empty answer', hostReason([], [], [], [], []), 'no answer');
check('reason: a filtered answer (0.0.0.0 or a local address) is no answer',
    [hostReason([['host' => 'h.example', 'type' => 'A', 'ip' => '0.0.0.0']], [], [], [], []),
     hostReason([['host' => 'h.example', 'type' => 'A', 'ip' => '10.0.0.1']], [], [], [], [])],
    ['no answer', 'no answer']);
check('reason: records, but none of the host\'s own',
    hostReason([['host' => 'h.cdn.example', 'type' => 'A', 'ip' => '198.51.100.7']], [], [], [], []), $cdn);
check('reason: every answer in a shared range', hostReason([$own], ['198.51.100.7'], [], $cloud, []), $cdn);
check('reason: every answer protected or never blocked, or some shared and the rest guarded',
    [hostReason([$own], ['198.51.100.7'], ['198.51.100.7'], [], []),
     hostReason([$own], ['198.51.100.7'], [], [], $cloud),
     hostReason([$own, $other], ['198.51.100.7', '203.0.113.9'], ['198.51.100.7'], ['203.0.113.0/24'], [])],
    array_fill(0, 3, 'every answer is guarded'));
check('reason: one answer left to block is no reason',
    hostReason([$own, $other], ['198.51.100.7', '203.0.113.9'], ['198.51.100.7'], [], []), null);

/* ---- the gaming cache ------------------------------------------------------ */

$empty = ['hosts' => [], 'proxy' => [], 'guard' => [], 'games' => [], 'refreshed_at' => null, 'errors' => []];
check('cache: a 0.3.0 cache keeps its proxy memory and drops its unlinked addresses',
    sanitizeGamingCache(['learned' => ['203.0.113.5' => 100],
        'protected' => ['gate.game.example' => ['addresses' => ['198.51.100.7'], 'seen' => 100]],
        'refreshed_at' => 5, 'errors' => ['x']]),
    ['hosts' => [], 'proxy' => ['gate.game.example' => ['addresses' => ['198.51.100.7'], 'seen' => 100]],
     'guard' => [], 'games' => [], 'refreshed_at' => 5, 'errors' => ['x']]);
check('cache: a proxy memory of its own wins over 0.3.0\'s',
    sanitizeGamingCache(['proxy' => [],
        'protected' => ['gate.game.example' => ['addresses' => ['198.51.100.7'], 'seen' => 100]]])['proxy'], []);
check('cache: anything but an object is an empty cache', [sanitizeGamingCache(null), sanitizeGamingCache('x')],
    [$empty, $empty]);
check('cache: damaged entries are dropped one by one',
    sanitizeGamingCache([
        'hosts' => ['h.example' => ['192.0.2.1' => 950, '10.0.0.1' => 950, '192.0.2.2' => '950'], 'k.example' => 'x'],
        'proxy' => ['p.example' => ['addresses' => ['198.51.100.7'], 'seen' => 990],
            'q.example' => ['addresses' => ['198.51.100.8', '10.0.0.1'], 'seen' => 990]],
        'guard' => ['g.example' => ['addresses' => ['203.0.113.5'], 'seen' => 990],
            'z.example' => ['addresses' => ['203.0.113.6'], 'seen' => '990']],
        'games' => ['u1' => ['addresses' => 2, 'ranges' => 0, 'why' => null],
            'u2' => ['addresses' => '2', 'ranges' => 0, 'why' => null],
            'u3' => ['addresses' => 0, 'ranges' => 0, 'why' => 'no answer'],
            'u4' => ['addresses' => 0, 'ranges' => 0, 'why' => 5], 'u5' => ['addresses' => 0, 'ranges' => 0]],
        'refreshed_at' => 'x', 'errors' => ['e', 5]]),
    ['hosts' => ['h.example' => ['192.0.2.1' => 950]],
     'proxy' => ['p.example' => ['addresses' => ['198.51.100.7'], 'seen' => 990]],
     'guard' => ['g.example' => ['addresses' => ['203.0.113.5'], 'seen' => 990]],
     'games' => ['u1' => ['addresses' => 2, 'ranges' => 0, 'why' => null],
         'u3' => ['addresses' => 0, 'ranges' => 0, 'why' => 'no answer']],
     'refreshed_at' => null, 'errors' => ['e']]);
$file = (string)tempnam(sys_get_temp_dir(), 'games');
$cache = ['hosts' => ['h.example' => ['192.0.2.1' => 950, '192.0.2.2' => 990]],
    'proxy' => ['p.example' => ['addresses' => ['198.51.100.7'], 'seen' => 990]], 'guard' => [],
    'games' => ['u1' => ['addresses' => 2, 'ranges' => 1, 'why' => null],
        'u2' => ['addresses' => 0, 'ranges' => 0, 'why' => 'switched off']],
    'refreshed_at' => 1000, 'errors' => ['lookup failed: k.example']];
writeGamingCache($file, $cache);
check('cache: written and read back the same, an empty map as {}, for its owner only',
    [readGamingCache($file), str_contains((string)file_get_contents($file), '"guard":{}'),
     decoct(fileperms($file) & 0777), file_exists($file . '.tmp')],
    [$cache, true, '600', false]);
unlink($file);
check('cache: a missing file reads as empty', readGamingCache($file), $empty);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
