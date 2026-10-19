<?php

/**
 * The messaging server list: reading messaging.json and the cache file, the
 * refresh that resolves and downloads the list and loads it into its pf table,
 * and dropping the open connections a new block should cut.
 *
 * The refresh and the drops go through configd, so they run only on the
 * firewall. sync.php loads this file after the other lib files, whose functions
 * and constants these call. The data file, the cache file and the table are
 * parameters: nothing here reads what sync.php defines.
 */

use OPNsense\Core\Backend;

/**
 * messaging.json, or null when it is missing or not readable as schema 1.
 *
 * @return ?array<string, mixed>
 */
function messagingData(string $path): ?array
{
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) && ($data['schema'] ?? null) === 1 ? $data : null;
}

/**
 * gaming.json, or null when it is missing or not readable as schema 1 with the
 * lists the gaming objects are built from.
 *
 * @return ?array<string, mixed>
 */
function gamingData(string $path): ?array
{
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
        return null;
    }
    foreach (['safe_udp_ports', 'tcp_ports', 'servers', 'never_blocked', 'alias_suffixes'] as $key) {
        if (!is_array($data[$key] ?? null)) {
            return null;
        }
    }
    return $data;
}

/**
 * The server list's cache: the learned addresses, the last good downloads, and
 * the last refresh's time and errors. A missing or damaged file reads as empty,
 * entry by entry (sanitizeCache()).
 *
 * @return array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: ?list<string>, refreshed_at: ?int, errors: list<string>}
 */
function readCache(string $path): array
{
    $raw = @file_get_contents($path);
    return sanitizeCache($raw === false ? null : json_decode($raw, true));
}

/**
 * Refresh the server list and load it into its table.
 *
 * Resolves the notification services' names and the selected apps' names, learns
 * the answers that the guard (protected names, shared CDNs) lets through unless a
 * protected name has no answer, this run or a recent one, downloads each published
 * list once a day (an hour after a failed download at the soonest), and makes the
 * servers table hold the learned addresses, the published ranges and the
 * notification services' ranges. A published range that holds a protected address
 * is left out (filterRanges()). A failed lookup or download never empties the
 * list: a learned address stays for a week, and a published list falls back to its
 * last good copy, then to the shipped one. Only taking an app out of the selection
 * starts the learned set again.
 *
 * @param array<string, mixed> $data  messaging.json
 * @param array<string>        $apps  the selected app ids
 * @param array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: ?list<string>, refreshed_at: ?int, errors: list<string>} $cache
 *        readCache()
 * @param bool $add false to only delete from the table (syncTable())
 * @return array{list<string>, list<string>, array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: list<string>, refreshed_at: int, errors: list<string>}}
 *         this run's servers, the ones the table gained, and the cache to write
 */
function refreshServerList(Backend $backend, string $table, array $data, array $apps, array $cache, bool $add): array
{
    /* one try of one second per name, so a dead resolver spends the budget on
       many names rather than the default several seconds on each */
    putenv('RES_OPTIONS=timeout:1 attempts:1');
    $lookup = function (string $host) {
        $records = @dns_get_record($host, DNS_A);
        return $records === false ? false : answerAddresses($records);
    };
    $nowMs = fn(): int => (int)(microtime(true) * 1000);
    $selected = array_intersect_key($data['apps'], array_flip($apps));
    $selectedIds = array_keys($selected);
    sort($selectedIds);

    $protected = protectedNames($data['protected_hosts'], $data['protected_owners'] ?? [], $selectedIds);
    $found = resolveAll(messagingHosts($selected, $data['notifications']), $lookup, 20000, $nowMs);
    $answered = resolveAll($protected, $lookup, 10000, $nowMs);
    $now = time();
    /* stamped with the hour, so the learned and protected sections change only when an
       answer does; the file itself is rewritten on every refresh, for its refresh time */
    $hour = intdiv($now, 3600) * 3600;
    $guard = protectedGuard($protected, $answered['answers'], $cache['protected'], $hour, 7 * 86400);
    $learned = updateLearned(
        learnedForSelection($cache['learned'], $cache['apps'], $selectedIds),
        $found['addresses'],
        $guard,
        $data['shared_cdn_ranges'],
        $hour,
        7 * 86400
    );
    $published = refreshPublished(
        $selected,
        $cache['published'],
        $now,
        86400,
        3600,
        downloadPublished(...)
    );

    /* every range, the shipped ones too, passes the same check before it can reach the
       table: a hand-edited or damaged data file must not cut devices off their LAN */
    $apps = checkedRanges($published['ranges']);
    $notify = checkedRanges([
        'Apple notifications' => $data['notifications']['apple_ranges'],
        'Windows notifications' => $data['notifications']['microsoft_ranges'],
    ]);
    $ranges = filterRanges(
        $apps['ranges'],
        array_merge($notify['ranges']['Apple notifications'], $notify['ranges']['Windows notifications']),
        $guard['addresses']
    );

    $dest = destinationSet(
        $learned,
        $ranges['ranges'],
        $notify['ranges']['Apple notifications'],
        $notify['ranges']['Windows notifications']
    );
    $diff = syncTable($backend, $table, $dest, $add);
    printf("messaging servers=%d added=%d removed=%d\n", count($dest), count($diff['added']), count($diff['removed']));

    return [$dest, $diff['added'], [
        'learned' => $learned,
        'published' => $published['published'],
        'protected' => $guard['memory'],
        'apps' => $selectedIds,
        'refreshed_at' => time(),
        'errors' => array_merge(
            refreshErrors([$found, $answered], $published['failed'], $guard['missing']),
            $apps['errors'],
            $notify['errors'],
            $ranges['errors'],
            $diff['errors']
        ),
    ]];
}

/**
 * The body at an https URL, or false unless the server answers 200 with a body.
 *
 * Only https is allowed, curl's own certificate checks apply, a redirect is not
 * followed, and the connection and the whole transfer each get ten seconds. A PHP
 * without the curl extension fails every download.
 *
 * @return string|false
 */
function downloadPublished(string $url): string|false
{
    /* curl, because OPNsense sets allow_url_fopen = 0, so file_get_contents() cannot open a URL there */
    if (!function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init($url);
    if ($ch === false || !curl_setopt_array($ch, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
    ])) {
        return false;
    }
    $body = curl_exec($ch);
    return is_string($body) && $body !== '' && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? $body : false;
}

/**
 * Replace the cache file in one step, so a run that dies half way leaves the old
 * file rather than half a new one. A failure goes to STDERR; the next run writes
 * it again.
 *
 * @param array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: ?list<string>, refreshed_at: ?int, errors: list<string>} $cache
 */
function writeCache(string $path, array $cache): void
{
    $tmp = $path . '.tmp';                  /* the sync lock keeps this name to one run */
    $json = json_encode([
        'learned' => (object)$cache['learned'],         /* {} rather than [] when empty */
        'published' => (object)$cache['published'],
        'protected' => (object)$cache['protected'],
        'apps' => $cache['apps'],
        'refreshed_at' => $cache['refreshed_at'],
        'errors' => $cache['errors'],
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || @file_put_contents($tmp, $json) === false
        || !@chmod($tmp, 0600) || !@rename($tmp, $path)) {
        fwrite(STDERR, 'cannot write ' . $path . "\n");     /* the messaging or the gaming cache */
    }
}

/**
 * Drop the open connections that this run's messaging block should cut, as
 * dropPlan() says for each device in the device table, so an open chat or
 * notification channel does not carry on. Nothing else is touched (see
 * selectStatesToDrop()).
 *
 * Best effort, device by device: a failure goes to STDERR and the next device is
 * still done. An exception is also returned, so that status can show it.
 *
 * @param array<string> $targets   every address in the device table
 * @param array<string> $added     the ones added this run
 * @param array<string> $dest      this run's servers; [] when the list was not refreshed
 * @param array<string> $addedDest the servers added to their table this run
 * @return list<string> the exceptions, one line each
 */
function dropMessagingStates(Backend $backend, array $targets, array $added, array $dest, array $addedDest): array
{
    $failures = [];
    foreach ($targets as $target) {
        try {
            $plan = dropPlan($target, $added, $dest, $addedDest, NOTIFY_PORT_LO, NOTIFY_PORT_HI);
            if ($plan === null) {
                continue;
            }
            /* every state with the target on either side: an empty limit, offset,
               label or sort is ignored */
            $raw = $backend->configdpRun('filter list states', [$target, '', '', '', '']);
            $rows = json_decode((string)$raw, true)['details'] ?? null;
            if (!is_array($rows)) {
                fwrite(STDERR, "messaging: cannot list the connections of $target\n");
                continue;
            }
            foreach (selectStatesToDrop($rows, $plan['destinations'], $plan['portLo'], $plan['portHi']) as $id) {
                /* "hexid/hexcreator", as core's own state page passes it */
                if (!preg_match('/^([0-9a-f]+)\/([0-9a-f]+)$/i', $id, $m)) {
                    fwrite(STDERR, "messaging: not a state id: $id\n");
                    continue;
                }
                $answer = (string)$backend->configdpRun('filter kill state', [$m[1], $m[2]]);
                if (!stateKilled($answer)) {
                    fwrite(STDERR, "messaging: dropping state $id failed: " . trim($answer) . "\n");
                }
            }
        } catch (\Throwable $e) {
            $failures[] = "dropping connections of $target failed: " . $e->getMessage();
            fwrite(STDERR, 'messaging: ' . end($failures) . "\n");
        }
    }
    return $failures;
}

/**
 * Refresh the game-server list and load it into its table.
 *
 * Resolves gaming.json's game hosts, keeping only the answers that are the hosts'
 * own (plainAnswers()) and not on a shared CDN; learns them for a week, as the
 * messaging list does, since a game's load balancers change address; and makes the
 * table hold them and the games' shipped ranges, each through acceptRange(). A
 * failed lookup never empties the list.
 *
 * @param array<string, mixed> $gaming    gaming.json
 * @param array<string>        $cdnRanges messaging.json's shared_cdn_ranges; [] when it is unreadable
 * @param array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: ?list<string>, refreshed_at: ?int, errors: list<string>} $cache
 *        readCache() of the gaming cache
 * @param bool $add false to only delete from the table (syncTable())
 * @return array{list<string>, list<string>, array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: list<string>, refreshed_at: int, errors: list<string>}}
 *         this run's servers, the ones the table gained, and the cache to write
 */
function refreshGamingServers(Backend $backend, string $table, array $gaming, array $cdnRanges, array $cache, bool $add): array
{
    putenv('RES_OPTIONS=timeout:1 attempts:1');
    $suffixes = array_values(array_filter((array)($gaming['alias_suffixes'] ?? []), 'is_string'));
    $lookup = function (string $host) use ($suffixes) {
        $records = @dns_get_record($host, DNS_A);
        return $records === false ? false : plainAnswers($records, $host, $suffixes);
    };
    $servers = (array)($gaming['servers'] ?? []);
    $hosts = [];
    foreach ($servers as $server) {
        $hosts = array_merge($hosts, array_filter((array)(((array)$server)['hosts'] ?? []), 'is_string'));
    }
    $found = resolveAll(array_values(array_unique($hosts)), $lookup, 10000, fn(): int => (int)(microtime(true) * 1000));
    $hour = intdiv(time(), 3600) * 3600;
    $learned = updateLearned($cache['learned'], $found['addresses'], ['addresses' => [], 'missing' => []],
        $cdnRanges, $hour, 7 * 86400);
    $checked = checkedRanges(['game servers' => gamingDestinations($learned, $servers)]);
    $dest = $checked['ranges']['game servers'];
    $diff = syncTable($backend, $table, $dest, $add);
    printf("game servers=%d added=%d removed=%d\n", count($dest), count($diff['added']), count($diff['removed']));

    return [$dest, $diff['added'], [
        'learned' => $learned,
        'published' => [],
        'protected' => [],
        'apps' => [],
        'refreshed_at' => time(),
        'errors' => array_merge(refreshErrors([$found], [], []), $checked['errors'], $diff['errors']),
    ]];
}

/**
 * Drop the open connections that this run's gaming block should cut, as
 * gamingDropPlan() says for each device in the gaming device table, so a match in
 * progress ends. Nothing else is touched (see selectGamingStates()).
 *
 * Best effort, device by device, as dropMessagingStates(): a failure goes to STDERR
 * and the next device is still done; an exception is also returned for status.
 *
 * @param array<string>          $targets      every address in the device table
 * @param array<string>          $added        the ones added this run
 * @param array<string>          $exempt       neverBlocked() of this run
 * @param array<array{int, int}> $safeUdp      the safe-list
 * @param array<array{int, int}> $tcpPorts     the game TCP ports
 * @param array<string>          $servers      this run's game servers
 * @param array<string>          $addedServers the game servers added this run
 * @return list<string> the exceptions, one line each
 */
function dropGamingStates(Backend $backend, array $targets, array $added, array $exempt, array $safeUdp,
    array $tcpPorts, array $servers, array $addedServers): array
{
    $failures = [];
    foreach ($targets as $target) {
        try {
            $plan = gamingDropPlan($target, $added, $servers, $addedServers);
            if ($plan === null) {
                continue;
            }
            /* every state with the target on either side, as for messaging */
            $raw = $backend->configdpRun('filter list states', [$target, '', '', '', '']);
            $rows = json_decode((string)$raw, true)['details'] ?? null;
            if (!is_array($rows)) {
                fwrite(STDERR, "gaming: cannot list the connections of $target\n");
                continue;
            }
            foreach (selectGamingStates($rows, $exempt, $safeUdp, $tcpPorts, $plan['servers'], $plan['ports']) as $id) {
                /* "hexid/hexcreator", as core's own state page passes it */
                if (!preg_match('/^([0-9a-f]+)\/([0-9a-f]+)$/i', $id, $m)) {
                    fwrite(STDERR, "gaming: not a state id: $id\n");
                    continue;
                }
                $answer = (string)$backend->configdpRun('filter kill state', [$m[1], $m[2]]);
                if (!stateKilled($answer)) {
                    fwrite(STDERR, "gaming: dropping state $id failed: " . trim($answer) . "\n");
                }
            }
        } catch (\Throwable $e) {
            $failures[] = "dropping connections of $target failed: " . $e->getMessage();
            fwrite(STDERR, 'gaming: ' . end($failures) . "\n");
        }
    }
    return $failures;
}
