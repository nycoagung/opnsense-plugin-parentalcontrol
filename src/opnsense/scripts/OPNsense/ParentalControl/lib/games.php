<?php

/**
 * The game list's rules: a game as the settings hold it, which shipped games join the
 * list, the checks a game must pass to be saved, and the ranges no game may block.
 *
 * The parent keeps the game servers in the settings. The list starts as gaming.json's
 * servers, and a later release adds only the games it newly ships: the parent's edits
 * stay, and a shipped game the parent deleted never comes back. A game is blocked by
 * its hosts' own addresses, its proxy hosts' and its ranges, and none of them may be a
 * service that other sites share.
 *
 * No side effects and no OPNsense classes, like lib/messaging.php and lib/gaming.php,
 * which this file needs loaded first (with lib/markers.php). jsonSchema1() reads a
 * file; nothing else does I/O.
 */

/**
 * One game as the settings store it: the lists comma-separated, enabled '1' or '0'.
 * Each list comes back trimmed, without blanks or repeats, in first-seen order; host
 * names lower-cased, as DNS compares them.
 *
 * @param array<string, mixed> $fields enabled, name, hosts, proxy_hosts and ranges
 * @return array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}
 */
function gameEntry(array $fields): array
{
    return [
        'name' => trim((string)($fields['name'] ?? '')),
        'enabled' => (string)($fields['enabled'] ?? '') === '1',
        'hosts' => listField((string)($fields['hosts'] ?? ''), true),
        'proxy_hosts' => listField((string)($fields['proxy_hosts'] ?? ''), true),
        'ranges' => listField((string)($fields['ranges'] ?? ''), false),
    ];
}

/**
 * A comma-separated list, trimmed, without blanks or repeats, in first-seen order.
 *
 * @return list<string>
 */
function listField(string $value, bool $lower): array
{
    $items = array_map('trim', explode(',', $lower ? strtolower($value) : $value));
    return array_values(array_unique(array_filter($items, fn(string $item): bool => $item !== '')));
}

/**
 * A gaming.json server as a game of the list: named by its label, switched on.
 *
 * @param array<mixed> $server
 * @return array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}
 */
function shippedEntry(array $server): array
{
    $strings = fn(string $key): array => array_values(array_filter((array)($server[$key] ?? []), 'is_string'));
    return ['name' => is_string($server['label'] ?? null) ? $server['label'] : '', 'enabled' => true,
        'hosts' => $strings('hosts'), 'proxy_hosts' => $strings('proxy_hosts'), 'ranges' => $strings('ranges')];
}

/**
 * Which shipped games join the list.
 *
 * A key the list has never held is new. It joins, as shippedEntry() and in
 * gaming.json's order, unless the list already has a game of its name (ignoring case)
 * or one of its host names, which it would duplicate: then one message says so. Every
 * new key is held from then on, joined or not, so a game the parent deleted, or one
 * skipped, is never offered again.
 *
 * @param array<mixed>  $servers gaming.json's servers, key => server
 * @param array<string> $seen    the keys the list has held
 * @param array<array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}> $entries the list's games
 * @return array{add: array<string, array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}>, clashes: list<string>, seen: list<string>}
 */
function gamesToAdd(array $servers, array $seen, array $entries): array
{
    $names = [];
    $hosts = [];
    $take = function (array $game) use (&$names, &$hosts): void {
        $names[strtolower($game['name'])] = $game['name'];
        foreach (array_merge($game['hosts'], $game['proxy_hosts']) as $host) {
            $hosts[$host] = true;
        }
    };
    foreach ($entries as $entry) {
        $take($entry);
    }
    $seen = array_values($seen);
    $add = [];
    $clashes = [];
    foreach ($servers as $key => $server) {
        $key = (string)$key;
        if (in_array($key, $seen, true) || !is_array($server)) {
            continue;
        }
        $seen[] = $key;
        $game = shippedEntry($server);
        $named = $names[strtolower($game['name'])] ?? null;
        $taken = array_values(array_filter(array_merge($game['hosts'], $game['proxy_hosts']),
            fn(string $host): bool => isset($hosts[$host])));
        if ($named !== null) {
            $clashes[] = "{$game['name']} not added: the list already has a game named $named";
        } elseif ($taken !== []) {
            $clashes[] = "{$game['name']} not added: the list already has {$taken[0]}";
        } else {
            $add[$key] = $game;
            $take($game);
        }
    }
    return ['add' => $add, 'clashes' => $clashes, 'seen' => $seen];
}

/**
 * What keeps a game from being saved: each field with its first problem, worded for
 * the form. The other games' names and host names are taken; a host may not be both a
 * host and a proxy host of one game; no host may sit on a shared hosting service, and
 * no range may leave public space or overlap a range that is never blocked.
 *
 * @param array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>} $entry
 * @param array<array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}> $others
 * @param array<string> $sharedDomains gaming.json's shared_hosting_domains
 * @param array<string> $guarded       IPv4 CIDRs no game's range may overlap
 * @return array<string, string> field => message
 */
function gameProblems(array $entry, array $others, array $sharedDomains, array $guarded): array
{
    $problems = [];
    $owners = [];
    foreach ($others as $other) {
        if (strtolower($other['name']) === strtolower($entry['name'])) {
            $problems['name'] = 'Another game already has this name.';
        }
        foreach (array_merge($other['hosts'], $other['proxy_hosts']) as $host) {
            $owners[$host] ??= $other['name'];
        }
    }
    if ($entry['hosts'] === [] && $entry['proxy_hosts'] === [] && $entry['ranges'] === []) {
        $problems['hosts'] = 'Add at least one host, proxy host or address range.';
    }
    $ownHosts = array_fill_keys($entry['hosts'], $entry['name']);
    foreach (['hosts' => $owners, 'proxy_hosts' => $owners + $ownHosts] as $field => $taken) {
        foreach ($entry[$field] as $host) {
            if (isset($taken[$host])) {
                $problems[$field] ??= "$host is already listed for {$taken[$host]}.";
            } elseif (onSharedDomain($host, $sharedDomains)) {
                $problems[$field] ??= "$host is on a shared hosting service, so blocking it would block other sites too.";
            }
        }
    }
    foreach ($entry['ranges'] as $range) {
        if (!isIpv4Cidr($range) || reachesNonPublic($range)) {
            $problems['ranges'] ??= "$range is not a public IPv4 network.";
        } elseif (array_filter($guarded, fn(string $g): bool => ipv4InCidr(explode('/', $range)[0], $g)
                || ipv4InCidr(explode('/', $g)[0], $range)) !== []) {
            $problems['ranges'] ??= "$range overlaps a shared service that is never blocked.";
        }
    }
    return $problems;
}

/**
 * Is $host one of $domains, or a name below one?
 *
 * @param array<string> $domains
 */
function onSharedDomain(string $host, array $domains): bool
{
    foreach ($domains as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }
    return false;
}

/**
 * Where no game may block an address: the shared CDNs' ranges (messaging.json) and
 * the shared front ends (gaming.json), each range once, sorted.
 *
 * @param array<mixed>  $gaming    gaming.json
 * @param ?array<mixed> $messaging messaging.json, or null when it is unreadable
 * @return list<string>
 */
function guardedRanges(array $gaming, ?array $messaging): array
{
    $all = array_filter((array)($messaging['shared_cdn_ranges'] ?? []), 'is_string');
    foreach ((array)($gaming['shared_front_end_ranges'] ?? []) as $list) {
        $all = array_merge($all, array_filter((array)$list, 'is_string'));
    }
    $all = array_values(array_unique($all));
    sort($all, SORT_STRING);
    return $all;
}

/**
 * A data file of the plugin's, decoded, when it reads as schema 1; otherwise null.
 *
 * @return ?array<mixed>
 */
function jsonSchema1(string $path): ?array
{
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) && ($data['schema'] ?? null) === 1 ? $data : null;
}

/**
 * gaming.json's servers, the games the plugin ships, or null when the file cannot
 * be read as schema 1 with a servers object.
 *
 * @return ?array<mixed>
 */
function shippedServers(string $path): ?array
{
    $servers = jsonSchema1($path)['servers'] ?? null;
    return is_array($servers) ? $servers : null;
}
