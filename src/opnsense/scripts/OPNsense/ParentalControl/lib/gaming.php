<?php

/**
 * Pure functions for the gaming block: the per-device decision, the port lists of
 * its rules, the destinations never treated as gaming, which DNS answers for a game
 * host may be learned, and the choice of open connections to drop.
 *
 * Real-time games send their play as UDP to ports of their own, so a device denied
 * gaming may send UDP to the internet only on a short safe-list of ports that calls,
 * Wi-Fi Calling, the web and the clock need; a few games play over TCP ports of
 * their own; and a few games are known by their servers' addresses.
 *
 * No I/O, no side effects and no OPNsense classes, like lib/messaging.php, which
 * this file needs loaded first (with lib/markers.php).
 */

/* Destinations that are not the internet: private space, link-local, multicast and
   broadcast. DHCP, mDNS, SSDP and games on the local network go to these. */
const GAME_LOCAL = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '224.0.0.0/4',
    '255.255.255.255'];

/**
 * Should this device be denied gaming right now? categoryDecision() for gaming, so
 * it follows exactly the rules messaging follows.
 *
 * @param array{bool, string} $schedule scheduleDecision() for this device
 * @return array{bool, string} [blocked, reason]
 */
function gamingDecision(bool $pluginEnabled, bool $deviceEnabled, string $setting, array $schedule): array
{
    return categoryDecision('gaming', $pluginEnabled, $deviceEnabled, $setting, $schedule);
}

/**
 * Every port from 1 to 65535 that $safe does not hold, as ordered [lo, hi] ranges.
 *
 * The UDP rule blocks these. It is written this way, rather than as a pass rule for
 * the safe ports, because a pass rule would let a gaming-blocked device through
 * every block after it, the internet switch and the user's own rules included.
 * $safe may be in any order and its ranges may overlap.
 *
 * @param array<array{int, int}> $safe
 * @return list<array{int, int}>
 */
function complementPorts(array $safe): array
{
    usort($safe, fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $out = [];
    $next = 1;                          /* the lowest port not yet accounted for */
    foreach ($safe as [$lo, $hi]) {
        if ($lo > $next) {
            $out[] = [$next, $lo - 1];
        }
        $next = max($next, $hi + 1);
    }
    if ($next <= 65535) {
        $out[] = [$next, 65535];
    }
    return $out;
}

/**
 * A port alias's content: one entry per line, a single port as itself and a range
 * as lo:hi, the form OPNsense's port aliases take.
 *
 * @param array<array{int, int}> $ranges
 */
function portAliasContent(array $ranges): string
{
    return implode("\n", array_map(fn(array $r): string => $r[0] === $r[1] ? (string)$r[0] : "$r[0]:$r[1]", $ranges));
}

/**
 * The firewall's own IPv4 addresses, read from `ifconfig -a`: every inet line of
 * every interface, alias addresses included, but not loopback. Sorted, each once.
 *
 * A device reaches these to use a VPN back home or a service forwarded on the home's
 * public address, which is not the internet and not a game.
 *
 * @return list<string>
 */
function firewallIpv4(string $ifconfig): array
{
    preg_match_all('/^\s+inet\s+(\S+)/m', $ifconfig, $m);
    $own = array_filter($m[1], fn(string $ip): bool => isIpv4($ip) && !ipv4InCidr($ip, '127.0.0.0/8'));
    $own = array_values(array_unique($own));
    sort($own, SORT_STRING);
    return $own;
}

/**
 * Where a device denied gaming may still send UDP on any port, and TCP on the game
 * ports: the local set (GAME_LOCAL), the firewall's own addresses, gaming.json's
 * never_blocked networks (Discord's, for its voice), and, when the messaging data
 * is readable, every messaging app's shipped networks, so their calls keep working
 * on whatever port they use. Those networks hold no other company's games. In pf's
 * own form, each once, sorted.
 *
 * @param array<string>        $own       firewallIpv4()
 * @param array<string, mixed> $gaming    gaming.json
 * @param ?array<string, mixed> $messaging messaging.json, or null when unreadable
 * @return list<string>
 */
function neverBlocked(array $own, array $gaming, ?array $messaging): array
{
    $all = array_merge(GAME_LOCAL, $own);
    foreach ((array)($gaming['never_blocked'] ?? []) as $list) {
        $all = array_merge($all, (array)$list);
    }
    foreach ((array)($messaging['apps'] ?? []) as $app) {
        $app = (array)$app;
        $all = array_merge($all, (array)($app['ranges'] ?? []), (array)($app['extra_ranges'] ?? []),
            (array)($app['site_ranges'] ?? []));
    }
    $all = array_values(array_unique(array_map('canonicalTarget', array_filter($all, 'is_string'))));
    sort($all, SORT_STRING);
    return $all;
}

/**
 * Does the never-blocked table hold the whole local set? Until it does, no device
 * may enter the gaming device table: an empty or half-loaded table would make the
 * UDP rule block a device's own network, DHCP and the firewall's DNS included.
 *
 * @param array<string> $tableEntries the table as pf lists it
 */
function exemptReady(array $tableEntries): bool
{
    return array_diff(GAME_LOCAL, $tableEntries) === [];
}

/**
 * The addresses in one game host's dns_get_record() answer that may be blocked.
 *
 * An answer counts when it is a plain record of the name asked, or comes through an
 * alias ending in one of $aliasSuffixes: a load balancer of the game's own. Through
 * any other alias - a CDN's - the address serves other sites too, and is left alone.
 * Names are compared case-insensitively, without a trailing dot. Only public
 * addresses count (isPublicIpv4()): a resolver that sinkholes the name answers
 * 0.0.0.0 or a local address.
 *
 * @param array<mixed> $records
 * @param array<string> $aliasSuffixes
 * @return list<string>
 */
function plainAnswers(array $records, string $host, array $aliasSuffixes): array
{
    $asked = rtrim(strtolower($host), '.');
    $out = [];
    foreach ($records as $record) {
        if (!is_array($record) || !is_string($record['ip'] ?? null) || !is_string($record['host'] ?? null)) {
            continue;
        }
        $owner = rtrim(strtolower($record['host']), '.');
        $own = $owner === $asked;
        foreach ($aliasSuffixes as $suffix) {
            $own = $own || str_ends_with($owner, strtolower($suffix));
        }
        if ($own && isPublicIpv4($record['ip'])) {
            $out[] = $record['ip'];
        }
    }
    return $out;
}

/**
 * What the game-servers table holds: the addresses learned from the game hosts and
 * every game's shipped ranges, each once, sorted.
 *
 * @param array<string, int>   $learned address => time last seen
 * @param array<string, mixed> $servers gaming.json's servers
 * @return list<string>
 */
function gamingDestinations(array $learned, array $servers): array
{
    $all = array_map('strval', array_keys($learned));
    foreach ($servers as $server) {
        $all = array_merge($all, array_filter((array)(((array)$server)['ranges'] ?? []), 'is_string'));
    }
    $all = array_values(array_unique($all));
    sort($all, SORT_STRING);
    return $all;
}

/**
 * Which open connections of one gaming-blocked device to drop this run; null for none.
 *
 * A device that entered the device table this run loses its game connections on the
 * rules' ports and everything to the game servers. One that was there already loses
 * only its connections to the servers added this run.
 *
 * @param array<string> $added        the device-table entries added this run
 * @param array<string> $servers      this run's game servers
 * @param array<string> $addedServers the game servers added this run
 * @return ?array{ports: bool, servers: list<string>}
 */
function gamingDropPlan(string $target, array $added, array $servers, array $addedServers): ?array
{
    if (in_array($target, $added, true)) {
        return ['ports' => true, 'servers' => array_values($servers)];
    }
    return $addedServers === [] ? null : ['ports' => false, 'servers' => array_values($addedServers)];
}

/**
 * The ids of the open connections the gaming rules would now block: any to a game
 * server; and, with $ports, UDP to a destination not in $exempt on a port outside
 * $safeUdp, and TCP to a destination not in $exempt on a port in $tcpPorts.
 *
 * Nothing else is selected. A row missing any key this reads is ignored: a state that
 * cannot be read in full is not killed on a guess. Single addresses (bare or /32)
 * are looked up and only real networks are scanned, as in selectStatesToDrop().
 *
 * @param list<array<string, string>> $records rows of core's `filter list states`;
 *        only id, proto, dst_addr and dst_port are read
 * @param array<string>          $exempt   neverBlocked()
 * @param array<array{int, int}> $safeUdp  the safe-list
 * @param array<array{int, int}> $tcpPorts the game TCP ports
 * @param array<string>          $servers  game-server addresses and networks
 * @return list<string> the ids ("hexid/hexcreator"), unique, in input order
 */
function selectGamingStates(array $records, array $exempt, array $safeUdp, array $tcpPorts, array $servers,
    bool $ports): array
{
    $isExempt = destinationMatcher($exempt);
    $isServer = destinationMatcher($servers);
    $inRanges = function (int $port, array $ranges): bool {
        foreach ($ranges as [$lo, $hi]) {
            if ($port >= $lo && $port <= $hi) {
                return true;
            }
        }
        return false;
    };
    $ids = [];
    foreach ($records as $row) {
        if (!isset($row['id'], $row['proto'], $row['dst_addr'], $row['dst_port'])) {
            continue;
        }
        $addr = $row['dst_addr'];
        $port = (int)$row['dst_port'];
        $proto = strtolower($row['proto']);
        $game = $ports && in_array($proto, ['udp', 'tcp'], true) && !$isExempt($addr)
            && ($proto === 'udp' ? !$inRanges($port, $safeUdp) : $inRanges($port, $tcpPorts));
        if ($game || $isServer($addr)) {
            $ids[] = $row['id'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * A test for "is this address one of $destinations?": single addresses (bare or /32)
 * by lookup, real networks by scan, so thousands of /32s cost no more than a few.
 *
 * @param array<string> $destinations IPv4 addresses or CIDRs
 * @return callable(string): bool
 */
function destinationMatcher(array $destinations): callable
{
    $hosts = [];
    $networks = [];
    foreach ($destinations as $dest) {
        if (!str_contains($dest, '/') || str_ends_with($dest, '/32')) {
            $hosts[explode('/', $dest)[0]] = true;
        } else {
            $networks[] = $dest;
        }
    }
    return fn(string $addr): bool => isset($hosts[$addr]) || ipv4InAny($addr, $networks);
}
