#!/usr/local/bin/php
<?php
/**
 * Invariants of gaming.json, the shipped data that decides what a device denied
 * gaming may still send and which game servers it cannot reach.
 *
 * Needs PHP with the ctype and filter extensions lib/messaging.php uses; no OPNsense,
 * no network. A wrong entry here breaks something a child needs (a call, Wi-Fi
 * Calling, a notification) or lets a game through.
 *
 *   php tests/GamingDataTest.php [path]   (default: the shipped gaming.json)
 */

$lib = __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/lib';
require $lib . '/markers.php';
require $lib . '/messaging.php';

$scripts = __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl';
$path = $argv[1] ?? $scripts . '/gaming.json';
$text = is_readable($path) ? file_get_contents($path) : false;
$data = is_string($text) ? json_decode($text, true) : null;
if (!is_array($data)) {
    fwrite(STDERR, "could not read $path as JSON\n");
    exit(1);
}
$messaging = json_decode((string)file_get_contents($scripts . '/messaging.json'), true);

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

/* is $port inside any [lo, hi] of $ranges? */
function inRanges(int $port, array $ranges): bool
{
    foreach ($ranges as [$lo, $hi]) {
        if ($port >= $lo && $port <= $hi) {
            return true;
        }
    }
    return false;
}

/* the entries that break order, overlap or leave 1-65535; none for a good list */
function badRanges(mixed $ranges): array
{
    if (!is_array($ranges) || !array_is_list($ranges)) {
        return ['not a list'];
    }
    $bad = [];
    $previous = 0;
    foreach ($ranges as $r) {
        if (!is_array($r) || count($r) !== 2 || !is_int($r[0] ?? null) || !is_int($r[1] ?? null)
            || $r[0] < 1 || $r[1] > 65535 || $r[0] > $r[1] || $r[0] <= $previous) {
            $bad[] = $r;
            continue;
        }
        $previous = $r[1];
    }
    return $bad;
}

$safe = $data['safe_udp_ports'] ?? null;
$tcp = $data['tcp_ports'] ?? null;
$servers = $data['servers'] ?? [];

check('data: schema 1', $data['schema'] ?? null, 1);
check('data: the safe-list is the design\'s', $safe,
    [[53, 53], [123, 123], [443, 443], [500, 500], [3478, 3497], [4500, 4500], [8801, 8810], [10000, 10000],
     [16384, 16387], [16393, 16402], [19302, 19309]]);
check('data: the TCP game ports are the design\'s', $tcp,
    [[1119, 1120], [2099, 2099], [2567, 2567], [3724, 3724], [4530, 4533], [6112, 6119], [6695, 6709],
     [7348, 7351], [7500, 7509], [7770, 7770], [7777, 7777], [7800, 7802], [7850, 7854], [8393, 8400],
     [9090, 9093], [9339, 9339], [19090, 19093], [19132, 19133], [20500, 20500], [20510, 20510],
     [25565, 25565], [27777, 27777], [28960, 28960], [30000, 30009], [37000, 37100], [50010, 50014],
     [65010, 65010], [65050, 65050]]);
check('data: port lists are ordered, disjoint and within 1-65535',
    [badRanges($safe), badRanges($tcp)], [[], []]);

/* chat, push notifications, internet calls, the Xbox network, web proxies, BitTorrent
   and the Steam client itself: blocking any of these on TCP breaks what is not a game */
$needed = array_merge([5222, 5223, 5228, 5229, 5230, 5060, 5062, 3478, 3479, 3480, 3074, 4000, 8080, 8088, 8090,
    8443, 6881], range(27014, 27050));
check('data: no port other services depend on is a game port',
    array_values(array_filter($needed, fn(int $p): bool => is_array($tcp) && badRanges($tcp) === []
        && inRanges($p, $tcp))),
    []);
/* each would open a hole for games: Game Center's own ports, DNS over QUIC, Discord's
   range, Viber's, Webex's and TURN over DTLS */
check('data: the ports left off on purpose are not safe-listed',
    array_values(array_filter([4398, 16403, 16472, 853, 50000, 65535, 4244, 5242, 5243, 7985, 5004, 9000, 5349],
        fn(int $p): bool => is_array($safe) && badRanges($safe) === [] && inRanges($p, $safe))),
    []);

check('data: the game hosts are the design\'s',
    array_map(fn(array $s): array => ['hosts' => $s['hosts'] ?? [], 'ranges' => $s['ranges'] ?? []], $servers),
    [
        'pokemon_go' => ['hosts' => ['pgorelease.nianticlabs.com'], 'ranges' => []],
        'monster_hunter_now' => ['hosts' => ['magellan-rel.nianticlabs.com'], 'ranges' => []],
        'niantic_sign_in' => ['hosts' => ['identity.nianticlabs.com'], 'ranges' => []],
        'honkai_impact_3rd' => ['hosts' => [
            'outer-dp-overseas01.honkaiimpact3.com', 'outer-dp-usa01.honkaiimpact3.com',
            'outer-dp-jp01.honkaiimpact3.com', 'outer-dp-kr01.honkaiimpact3.com', 'outer-dp-asia01.honkaiimpact3.com',
            'westglobal01.honkaiimpact3.com', 'prelogin.global.honkaiimpact3.com', 'account-west.honkaiimpact3.com',
        ], 'ranges' => []],
        'infinity_nikki' => ['hosts' => [
            'x6us-gate.infoldgames.com', 'x6en-gate.infoldgames.com', 'x6tw-gate.infoldgames.com',
            'x6asia-gate.infoldgames.com', 'x6us-auth.infoldgames.com', 'x6en-auth.infoldgames.com',
            'x6tw-auth.infoldgames.com', 'x6asia-auth.infoldgames.com',
        ], 'ranges' => []],
        'love_and_deepspace' => ['hosts' => [
            'x3asia-gatesvr.infoldgames.com', 'x3en-gatesvr.infoldgames.com', 'x3us-gatesvr.infoldgames.com',
            'x3tw-gatesvr.infoldgames.com', 'x3asia-loginsvr.infoldgames.com', 'x3en-loginsvr.infoldgames.com',
            'x3us-loginsvr.infoldgames.com', 'x3tw-loginsvr.infoldgames.com',
        ], 'ranges' => []],
        'vivox' => ['hosts' => [], 'ranges' => ['85.236.96.0/21', '85.236.104.0/23']],
    ]);
$hosts = array_merge(...array_values(array_map(fn(array $s): array => $s['hosts'] ?? [], $servers ?: [[]])));
check('data: every host is a lower-case DNS name, listed once',
    [array_values(array_filter($hosts, fn(string $h): bool =>
        !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h))),
     count($hosts) === count(array_unique($hosts))],
    [[], true]);
check('data: every game has a label',
    array_keys(array_filter($servers, fn(array $s): bool => !is_string($s['label'] ?? null) || $s['label'] === '')),
    []);
check('data: Discord\'s block is 66.22.192.0/18', $data['never_blocked'] ?? null, ['discord' => ['66.22.192.0/18']]);

$ranges = array_merge(
    ...array_values(array_map(fn(array $s): array => $s['ranges'] ?? [], $servers ?: [[]])),
    ...array_values($data['never_blocked'] ?? [[]])
);
check('data: every range is a public IPv4 network',
    array_values(array_filter($ranges, fn(string $r): bool => !isIpv4Cidr($r) || reachesNonPublic($r))),
    []);
$cdn = $messaging['shared_cdn_ranges'] ?? [];
check('data: no server range overlaps a shared CDN range',
    [count($cdn) > 100, array_values(array_filter($ranges, fn(string $r): bool => array_filter($cdn,
        fn(string $c): bool => ipv4InCidr(explode('/', $r)[0], $c) || ipv4InCidr(explode('/', $c)[0], $r)) !== []))],
    [true, []]);
check('data: alias suffixes are [\'.elb.amazonaws.com\']', $data['alias_suffixes'] ?? null, ['.elb.amazonaws.com']);

/* ---- the settings model ---------------------------------------------------- */

$modelPath = __DIR__ . '/../src/opnsense/mvc/app/models/OPNsense/ParentalControl/ParentalControl.xml';
$model = simplexml_load_file($modelPath);
if ($model === false) {
    fwrite(STDERR, "could not read $modelPath as XML\n");
    exit(1);
}
/* XPath, so a field missing from the model comes back empty and is reported */
$field = fn(string $path): string => (string)($model->xpath("/model/items/$path")[0] ?? '');
$gamingOptions = [];
foreach ($model->xpath('/model/items/devices/gaming/OptionValues/*') ?: [] as $option) {
    $gamingOptions[$option->getName()] = (string)$option;
}
/* a device saved before Gaming existed has no value and takes the default, so every
   existing device comes up as Not blocked; Required keeps the dialog from saving an
   empty choice */
check('model: Gaming offers off "Not blocked", on "Blocked", schedule "Blocked outside allowed hours", default off',
    [$gamingOptions, $field('devices/gaming/Default'), $field('devices/gaming/Required')],
    [['off' => 'Not blocked', 'on' => 'Blocked', 'schedule' => 'Blocked outside allowed hours'], 'off', 'Y']);
/* the internet alias may not take a gaming alias's name: the two would collide on
   every sync, and the internet block would stop */
$mask = $field('general/alias_name/Mask');
check('model: the internet alias name may not be any gaming alias name',
    [array_map(fn(string $name): int => (int)@preg_match($mask, $name), array_keys(GAME_ALIASES)),
     (int)@preg_match($mask, 'NoMessaging'), (int)@preg_match($mask, 'NoInternet'),
     (int)@preg_match($mask, 'NoGamingAtNight')],
    [[0, 0, 0, 0, 0], 0, 1, 1]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
