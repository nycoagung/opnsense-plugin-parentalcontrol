#!/usr/local/bin/php
<?php
/**
 * Table-driven tests for the game list's rules: a game's entry as the settings hold
 * it, which shipped games join the list, the checks a game must pass to be saved,
 * and the ranges no game may block.
 *
 * Runs anywhere PHP does - no OPNsense, no firewall, no network. A wrong answer here
 * is silent: a game the parent switched off stays blocked, a shipped game joins the
 * list twice, or a saved game blocks a shared service for every device denied gaming.
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

/* ---- entries ---------------------------------------------------------------- */

check('entry: lists split, trimmed, lower-cased, without blanks or repeats',
    gameEntry(['enabled' => '1', 'name' => 'Game', 'hosts' => 'A.example, ,b.example,a.example', 'proxy_hosts' => '',
        'ranges' => '192.0.2.0/24']),
    ['name' => 'Game', 'enabled' => true, 'hosts' => ['a.example', 'b.example'], 'proxy_hosts' => [],
        'ranges' => ['192.0.2.0/24']]);
check('entry: a switched-off game', row('Game', 'a.example', '', '', '0')['enabled'], false);
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
check('problems: a proxy host also listed as one of the game\'s own hosts',
    gameProblems(row('Mine', 'm.example', 'm.example'), [], $domains, $guarded),
    ['proxy_hosts' => 'm.example is already listed for Mine.']);
check('problems: a private or special-purpose range',
    gameProblems(row('Mine', 'm.example', '', '10.0.0.0/8'), [], $domains, $guarded),
    ['ranges' => '10.0.0.0/8 is not a public IPv4 network.']);
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

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
