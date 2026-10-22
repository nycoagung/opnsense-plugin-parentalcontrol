<?php

/**
 * The game list's rules: a game as the settings hold it, which shipped games join the
 * list, the checks a game must pass to be saved, the ranges no game may block, what
 * each game host is remembered to give, what each game blocks, and the gaming cache.
 *
 * The parent keeps the game servers in the settings. The list starts as gaming.json's
 * servers, and a later release adds only the games it newly ships: the parent's edits
 * stay, and a shipped game the parent deleted never comes back. A game is blocked by
 * its hosts' own addresses, its proxy hosts' and its ranges, and none of them may be a
 * service that other sites share. Each address is remembered with the host that gave
 * it, so a game switched off or deleted stops being blocked at the next sync.
 *
 * No side effects and no OPNsense classes, like lib/messaging.php and lib/gaming.php,
 * which this file needs loaded first (with lib/markers.php). jsonSchema1() and
 * readGamingCache() read a file and writeGamingCache() writes one; nothing else does
 * I/O.
 */

/**
 * One game as the settings store it: the lists comma-separated, enabled '1' or '0'.
 * Each list comes back trimmed, without blanks or repeats, in first-seen order; host
 * names lower-cased, as DNS compares them.
 *
 * @param array<string, string> $fields enabled, name, hosts, proxy_hosts and ranges
 * @return array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}
 */
function gameEntry(array $fields): array
{
    return [
        'name' => trim($fields['name'] ?? ''),
        'enabled' => ($fields['enabled'] ?? '') === '1',
        'hosts' => listField($fields['hosts'] ?? '', true),
        'proxy_hosts' => listField($fields['proxy_hosts'] ?? '', true),
        'ranges' => listField($fields['ranges'] ?? '', false),
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
 * host and a proxy host of one game; no host may sit on a shared hosting service; and
 * no range may leave public space, be wider than the table takes (acceptRange()), or
 * overlap a range that is never blocked.
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
        } elseif (acceptRange($range) === null) {
            $problems['ranges'] ??= "$range is wider than a /8, which no single service owns.";
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
 * The addresses in $addrs a game may block: none a protected name answered, none in
 * the never-blocked set, and, unless they are a proxy host's, none in a shared range.
 * A proxy host's answer lies in its provider's shared range by design (a per-game
 * proxy such as Cloudflare Spectrum gives the one name an address of its own), so
 * only the other two guards apply to it. Sorted, each once.
 *
 * @param array<string> $addrs          IPv4 addresses
 * @param array<string> $protectedAddrs what the protected names resolved to
 * @param array<string> $shared         guardedRanges()
 * @param array<string> $exempt         neverBlocked()
 * @return list<string>
 */
function blockable(array $addrs, array $protectedAddrs, array $shared, array $exempt, bool $proxy): array
{
    $kept = array_filter($addrs, fn(string $ip): bool => !in_array($ip, $protectedAddrs, true)
        && !ipv4InAny($ip, $exempt) && ($proxy || !ipv4InAny($ip, $shared)));
    $kept = array_unique($kept);
    sort($kept, SORT_STRING);
    return $kept;
}

/**
 * Each game host's remembered addresses after this run.
 *
 * A host answered this run adds its answers, stamped $now. One whose lookup failed or
 * ran out of time keeps what it had, so one slow minute does not unblock a game. An
 * address goes once it is more than $maxAge seconds old, or when blockable() refuses
 * it now, whichever host gave it. A host off the list ($hosts) goes at once, with
 * everything it gave, so switching a game off or deleting it takes effect at the next
 * sync; an address another host also gave stays with that host. A host with nothing
 * left is not kept. Hosts and addresses sorted.
 *
 * @param array<string, array<string, int>> $memory  host => (address => time last seen)
 * @param array<string, array<string>>      $answers this run's gameAnswers(), by host
 * @param array<string>                     $hosts   the plain hosts of the switched-on games
 * @param array<string> $protectedAddrs what the protected names resolved to
 * @param array<string> $shared         guardedRanges()
 * @param array<string> $exempt         neverBlocked()
 * @return array<string, array<string, int>>
 */
function learnHosts(array $memory, array $answers, array $hosts, array $protectedAddrs, array $shared,
    array $exempt, int $now, int $maxAge): array
{
    $out = [];
    foreach ($hosts as $host) {
        $seen = $memory[$host] ?? [];
        foreach ($answers[$host] ?? [] as $ip) {
            $seen[$ip] = $now;
        }
        $allowed = array_flip(blockable(array_map('strval', array_keys($seen)), $protectedAddrs, $shared, $exempt,
            false));
        $seen = array_filter(array_intersect_key($seen, $allowed), fn(int $time): bool => $time >= $now - $maxAge);
        if ($seen !== []) {
            ksort($seen, SORT_STRING);
            $out[$host] = $seen;
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * Every remembered address once, with the latest time any host gave it: the learned
 * set gamingDestinations() takes. Sorted by address.
 *
 * @param array<string, array<string, int>> $memory learnHosts()
 * @return array<string, int>
 */
function learnedAddresses(array $memory): array
{
    $all = [];
    foreach ($memory as $seen) {
        foreach ($seen as $ip => $time) {
            $all[$ip] = max($time, $all[$ip] ?? $time);
        }
    }
    ksort($all, SORT_STRING);
    return $all;
}

/**
 * Why one game host gives nothing to block this run, worded for the Games list, or
 * null when it does.
 *
 * Records with no public address are no answer: a filtering resolver answers a name it
 * filters with 0.0.0.0 or a local address. Records with nothing of the host's own came
 * through another provider's alias, a CDN's. Answers the guard refuses all lie in a
 * shared range, or some are guarded otherwise.
 *
 * @param array<mixed>|false|null $records dns_get_record($host, DNS_A): null when the
 *        lookup ran out of time, false when it failed
 * @param array<string> $answers        gameAnswers() of $records
 * @param array<string> $protectedAddrs what the protected names resolved to
 * @param array<string> $shared         guardedRanges(); [] for a proxy host, whose answers it never holds back
 * @param array<string> $exempt         neverBlocked()
 */
function hostReason(array|false|null $records, array $answers, array $protectedAddrs, array $shared,
    array $exempt): ?string
{
    if (blockable($answers, $protectedAddrs, $shared, $exempt, false) !== []) {
        return null;
    }
    if ($records === null) {
        return 'not looked up in time';
    }
    $public = array_filter($records === false ? [] : $records, fn(mixed $record): bool => is_array($record)
        && is_string($record['ip'] ?? null) && isPublicIpv4($record['ip']));
    if ($public === []) {
        return 'no answer';
    }
    if (array_filter($answers, fn(string $ip): bool => !ipv4InAny($ip, $shared)) === []) {
        return 'answers through a CDN or shared front end';
    }
    return 'every answer is guarded';
}

/**
 * What each game blocks this run, for the Games list: how many of the table's
 * addresses its hosts and proxy hosts gave, how many of its ranges the table holds,
 * and, when it blocks nothing, why. An address two games give counts for both. A game
 * switched off blocks nothing because it is off; otherwise the reason is the first of
 * its hosts' reasons in this order, the one most worth acting on: every answer
 * guarded, answers through a CDN, not looked up in time, and no answer, which is also
 * what a game gets when none of its hosts has a reason.
 *
 * @param array<string, array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}> $entries by uuid
 * @param array<string, array<string, int>>                        $memory      learnHosts()
 * @param array<string, array{addresses: list<string>, seen: int}> $proxyMemory the proxy hosts' kept answers
 * @param array<string, ?string> $why  hostReason() by host
 * @param array<string>          $dest the game-servers table's entries this run, in pf's own form
 * @return array<string, array{addresses: int, ranges: int, why: ?string}> by uuid
 */
function gameOutcomes(array $entries, array $memory, array $proxyMemory, array $why, array $dest): array
{
    $table = array_flip($dest);
    $out = [];
    foreach ($entries as $uuid => $entry) {
        if (!$entry['enabled']) {
            $out[$uuid] = ['addresses' => 0, 'ranges' => 0, 'why' => 'switched off'];
            continue;
        }
        $given = [];
        foreach ($entry['hosts'] as $host) {
            $given = array_merge($given, array_map('strval', array_keys($memory[$host] ?? [])));
        }
        foreach ($entry['proxy_hosts'] as $host) {
            $given = array_merge($given, $proxyMemory[$host]['addresses'] ?? []);
        }
        $addresses = count(array_intersect_key(array_flip($given), $table));
        $ranges = count(array_intersect_key(array_flip(array_map('canonicalTarget', $entry['ranges'])), $table));
        $reasons = array_intersect_key($why, array_flip(array_merge($entry['hosts'], $entry['proxy_hosts'])));
        $reason = null;
        if ($addresses === 0 && $ranges === 0) {
            $reason = 'no answer';
            foreach (['every answer is guarded', 'answers through a CDN or shared front end', 'not looked up in time']
                as $candidate) {
                if (in_array($candidate, $reasons, true)) {
                    $reason = $candidate;
                    break;
                }
            }
        }
        $out[$uuid] = ['addresses' => $addresses, 'ranges' => $ranges, 'why' => $reason];
    }
    return $out;
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

/**
 * Kept answers by name, as protectedGuard() keeps them, each judged on its own: a list
 * of public IPv4 addresses with an integer time, or the entry is dropped.
 *
 * @return array<string, array{addresses: list<string>, seen: int}>
 */
function keptAnswers(mixed $raw): array
{
    $kept = [];
    foreach (is_array($raw) ? $raw : [] as $name => $entry) {
        $seen = is_array($entry) ? $entry['seen'] ?? null : null;
        $list = is_array($entry) ? $entry['addresses'] ?? null : null;
        if (!is_int($seen) || !is_array($list) || !array_is_list($list)) {
            continue;
        }
        $addresses = [];
        foreach ($list as $address) {
            if (is_string($address) && isPublicIpv4($address)) {
                $addresses[] = $address;
            }
        }
        if (count($addresses) === count($list)) {
            $kept[(string)$name] = ['addresses' => $addresses, 'seen' => $seen];
        }
    }
    return $kept;
}

/**
 * The gaming cache, read defensively: each game host's remembered addresses
 * (learnHosts()), the proxy hosts' and the protected names' kept answers
 * (protectedGuard()), what each game blocked at the last refresh (gameOutcomes()),
 * and that refresh's time and errors.
 *
 * Each entry is judged on its own, as sanitizeCache() does: a damaged one is dropped
 * and the rest kept. A host's address must be public, with an integer time; a game's
 * counts integers, and its reason a string or null. 0.3.0's cache kept the proxy
 * hosts' answers as 'protected', which is read when there is no 'proxy'. Its learned
 * addresses are left behind: no host is known to have given them, so they could never
 * go when a game does, and the first refresh learns again from the list's hosts.
 * Anything but an array is an empty cache.
 *
 * @param mixed $raw the file's JSON, decoded to arrays
 * @return array{hosts: array<string, array<string, int>>, proxy: array<string, array{addresses: list<string>, seen: int}>, guard: array<string, array{addresses: list<string>, seen: int}>, games: array<string, array{addresses: int, ranges: int, why: ?string}>, refreshed_at: ?int, errors: list<string>}
 */
function sanitizeGamingCache(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $hosts = [];
    foreach ((array)($raw['hosts'] ?? []) as $host => $seen) {
        $kept = [];
        foreach (is_array($seen) ? $seen : [] as $ip => $time) {
            if (isPublicIpv4((string)$ip) && is_int($time)) {
                $kept[(string)$ip] = $time;
            }
        }
        if ($kept !== []) {
            $hosts[(string)$host] = $kept;
        }
    }
    $games = [];
    foreach ((array)($raw['games'] ?? []) as $uuid => $game) {
        $game = is_array($game) ? $game : [];
        $addresses = $game['addresses'] ?? null;
        $ranges = $game['ranges'] ?? null;
        $why = array_key_exists('why', $game) ? $game['why'] : false;
        if (is_int($addresses) && is_int($ranges) && ($why === null || is_string($why))) {
            $games[(string)$uuid] = ['addresses' => $addresses, 'ranges' => $ranges, 'why' => $why];
        }
    }
    $errors = [];
    foreach ((array)($raw['errors'] ?? []) as $error) {
        if (is_string($error)) {
            $errors[] = $error;
        }
    }
    return [
        'hosts' => $hosts,
        'proxy' => keptAnswers(array_key_exists('proxy', $raw) ? $raw['proxy'] : $raw['protected'] ?? null),
        'guard' => keptAnswers($raw['guard'] ?? null),
        'games' => $games,
        'refreshed_at' => is_int($raw['refreshed_at'] ?? null) ? $raw['refreshed_at'] : null,
        'errors' => $errors,
    ];
}

/**
 * The gaming cache file: a missing or damaged one reads as empty, entry by entry
 * (sanitizeGamingCache()).
 *
 * @return array{hosts: array<string, array<string, int>>, proxy: array<string, array{addresses: list<string>, seen: int}>, guard: array<string, array{addresses: list<string>, seen: int}>, games: array<string, array{addresses: int, ranges: int, why: ?string}>, refreshed_at: ?int, errors: list<string>}
 */
function readGamingCache(string $path): array
{
    $raw = @file_get_contents($path);
    return sanitizeGamingCache($raw === false ? null : json_decode($raw, true));
}

/**
 * Replace the gaming cache file in one step, as writeCache() does the messaging one,
 * so a run that dies half way leaves the old file rather than half a new one. Every
 * map is written as a JSON object, {} when empty. A failure goes to STDERR; the next
 * run writes it again.
 *
 * @param array{hosts: array<string, array<string, int>>, proxy: array<string, array{addresses: list<string>, seen: int}>, guard: array<string, array{addresses: list<string>, seen: int}>, games: array<string, array{addresses: int, ranges: int, why: ?string}>, refreshed_at: ?int, errors: list<string>} $cache
 */
function writeGamingCache(string $path, array $cache): void
{
    $tmp = $path . '.tmp';                  /* the sync lock keeps this name to one run */
    $json = json_encode([
        'hosts' => (object)array_map(fn(array $seen): object => (object)$seen, $cache['hosts']),
        'proxy' => (object)$cache['proxy'],
        'guard' => (object)$cache['guard'],
        'games' => (object)$cache['games'],
        'refreshed_at' => $cache['refreshed_at'],
        'errors' => $cache['errors'],
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || @file_put_contents($tmp, $json) === false
        || !@chmod($tmp, 0600) || !@rename($tmp, $path)) {
        fwrite(STDERR, 'cannot write ' . $path . "\n");
    }
}
