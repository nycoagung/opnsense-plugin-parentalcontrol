<?php

/**
 * The messaging server list: reading messaging.json and the cache file, the
 * refresh that resolves and downloads the list and loads it into its pf table,
 * and dropping the open connections a new block should cut.
 *
 * The refresh and the drops go through configd, so they run only on the
 * firewall. sync.php loads this file after the other lib files, whose functions
 * and constants these call; messagingData() reads sync.php's MSG_DATA_FILE.
 */

use OPNsense\Core\Backend;

/**
 * messaging.json, or null when it is missing or not readable as schema 1.
 *
 * @return ?array<string, mixed>
 */
function messagingData(): ?array
{
    $raw = @file_get_contents(MSG_DATA_FILE);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) && ($data['schema'] ?? null) === 1 ? $data : null;
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
 * notification services' ranges. A failed lookup or download never empties the
 * list: a learned address stays for a week, and a published list falls back to its
 * last good copy, then to the shipped one. Only taking an app out of the selection
 * starts the learned set again.
 *
 * @param array<string, mixed> $data  messaging.json
 * @param array<string>        $apps  the selected app ids
 * @param array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: ?list<string>, refreshed_at: ?int, errors: list<string>} $cache
 *        readCache()
 * @return array{list<string>, list<string>, array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: list<string>, refreshed_at: int, errors: list<string>}}
 *         this run's servers, the ones the table gained, and the cache to write
 */
function refreshServerList(Backend $backend, string $table, array $data, array $apps, array $cache): array
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

    $found = resolveAll(messagingHosts($selected, $data['notifications']), $lookup, 20000, $nowMs);
    $answered = resolveAll($data['protected_hosts'], $lookup, 10000, $nowMs);
    $now = time();
    /* stamped with the hour, so the cache changes only when an answer does */
    $hour = intdiv($now, 3600) * 3600;
    $guard = protectedGuard($data['protected_hosts'], $answered['answers'], $cache['protected'], $hour, 7 * 86400);
    $learned = updateLearned(
        learnedForSelection($cache['learned'], $cache['apps'], $selectedIds),
        $found['addresses'],
        $guard,
        $data['shared_cdn_ranges'],
        $hour,
        7 * 86400
    );
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $published = refreshPublished(
        $selected,
        $cache['published'],
        $now,
        86400,
        3600,
        fn(string $url) => @file_get_contents($url, false, $context)
    );

    $dest = destinationSet(
        $learned,
        $published['ranges'],
        $data['notifications']['apple_ranges'],
        $data['notifications']['microsoft_ranges']
    );
    $diff = syncTable($backend, $table, $dest);
    printf("messaging servers=%d added=%d removed=%d\n", count($dest), count($diff['added']), count($diff['removed']));

    return [$dest, $diff['added'], [
        'learned' => $learned,
        'published' => $published['published'],
        'protected' => $guard['memory'],
        'apps' => $selectedIds,
        'refreshed_at' => time(),
        'errors' => refreshErrors([$found, $answered], $published['failed'], $guard['missing']),
    ]];
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
        fwrite(STDERR, 'messaging: cannot write ' . $path . "\n");
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
