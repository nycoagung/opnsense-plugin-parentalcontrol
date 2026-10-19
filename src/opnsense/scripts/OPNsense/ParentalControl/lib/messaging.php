<?php

/**
 * Pure functions for the messaging block: the per-device decision, the IPv4
 * helpers, the builders of the server list and the reading of its cache, the
 * choice of connections to drop and the two checks setup and uninstall make
 * before touching an alias.
 *
 * No I/O, no side effects and no OPNsense classes, so this file loads anywhere
 * PHP runs: on the firewall from sync.php, and from tests/MessagingTest.php on
 * any machine. Everything that touches the firewall stays out of this file.
 */

/**
 * Should this device be denied messaging right now?
 *
 * First match wins. Mode and Override do not take part: they steer only the
 * whole-internet switch. An unrecognised setting blocks, because a damaged
 * setting must not quietly let messaging through.
 *
 * @param array{bool, string} $schedule scheduleDecision() for this device
 * @return array{bool, string} [blocked, reason]
 */
function messagingDecision(bool $pluginEnabled, bool $deviceEnabled, string $setting, array $schedule): array
{
    if (!$pluginEnabled) {
        return [false, 'plugin disabled'];
    }
    if (!$deviceEnabled) {
        return [false, 'device disabled'];
    }
    if ($setting === 'off') {
        return [false, 'messaging allowed'];
    }
    if ($setting === 'on') {
        return [true, 'messaging always blocked'];
    }
    if ($setting === 'schedule') {
        return [$schedule[0], 'messaging: ' . $schedule[1]];
    }
    return [true, 'unknown messaging setting'];
}

/**
 * A bare IPv4 address in strict dotted-quad form: no prefix, no leading zeros.
 *
 * The one place this rule lives. Everything that needs to know whether a string is
 * an address asks here, so two parts of the plugin cannot come to disagree about
 * it. It goes through filter_var, not ip2long(): ip2long() reads '192.0.2.010' as
 * 192.0.2.10 while inet_aton reads it as 192.0.2.8, and one range meaning two
 * networks is how the wrong thing gets blocked.
 */
function isIpv4(string $s): bool
{
    return filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

/**
 * A bare IPv4 address, or a.b.c.d/n with n from 0 to 32.
 */
function isIpv4Cidr(string $s): bool
{
    $p = explode('/', $s);
    return count($p) <= 2
        && isIpv4($p[0])
        && (count($p) === 1 || (ctype_digit($p[1]) && (int)$p[1] <= 32));
}

/**
 * Is $ip inside $cidr? A bare address as $cidr is a /32. Anything invalid, in
 * either argument, is false, so a bad range can never match.
 */
function ipv4InCidr(string $ip, string $cidr): bool
{
    if (!isIpv4Cidr($cidr) || !isIpv4($ip)) {
        return false;
    }
    [$net, $bits] = array_pad(explode('/', $cidr), 2, '32');
    $mask = (~0 << (32 - (int)$bits)) & 0xFFFFFFFF;
    return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
}

/**
 * Is $ip inside any of $cidrs? An empty list matches nothing.
 *
 * @param array<string> $cidrs
 */
function ipv4InAny(string $ip, array $cidrs): bool
{
    foreach ($cidrs as $cidr) {
        if (ipv4InCidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * A public IPv4 address: isIpv4(), outside the private and reserved ranges, and
 * outside the shared address space of carrier-grade NAT (100.64.0.0/10).
 *
 * A DNS answer must pass this before it is learned: a resolver that sinkholes a
 * name answers 0.0.0.0 or a local address, and blocking that would cut a device off
 * from its own network. Device addresses are not held to it.
 */
function isPublicIpv4(string $s): bool
{
    $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return filter_var($s, FILTER_VALIDATE_IP, $flags) !== false && !ipv4InCidr($s, '100.64.0.0/10');
}

/**
 * A device target or table entry as pfctl lists it: an IPv4 CIDR with host bits set
 * becomes its network, so 192.0.2.10/24 is 192.0.2.0/24, and a /32 becomes the bare
 * address, since pfctl prints a prefix only when it is shorter than a host's.
 * Anything else comes back as it is: an address, a CIDR already in network form, and
 * whatever is not an IPv4 CIDR.
 *
 * The device field accepts host bits and /32, but pf keeps the network form and
 * lists a host bare, so the table diff would never settle on the typed one; core's
 * state filter rejects host bits, so the drops would find nothing; and the internet
 * switch's state kill takes only a host.
 */
function canonicalTarget(string $target): string
{
    if (!isIpv4Cidr($target) || !str_contains($target, '/')) {
        return $target;
    }
    [$net, $bits] = explode('/', $target);
    $mask = (~0 << (32 - (int)$bits)) & 0xFFFFFFFF;
    $net = long2ip(ip2long($net) & $mask);
    return (int)$bits === 32 ? $net : $net . '/' . (int)$bits;
}

/**
 * What to add to and delete from a pf table so that it holds $want, matching each
 * entry the way pf lists it (canonicalTarget()). Compared as written, a wanted /32,
 * such as a notification service's address, never matches its bare listing, so it
 * would be deleted on one run and added back on the next.
 *
 * @param array<string> $want    the entries wanted, as written
 * @param array<string> $current the entries the table lists, in pf's own form
 * @return array{added: list<string>, removed: list<string>} added as written in
 *         $want, removed as listed
 */
function tableDiff(array $want, array $current): array
{
    $listed = array_flip($current);
    $wanted = array_flip(array_map('canonicalTarget', $want));
    return [
        'added' => array_values(array_filter($want, fn(string $w): bool => !isset($listed[canonicalTarget($w)]))),
        'removed' => array_values(array_filter($current, fn(string $c): bool => !isset($wanted[$c]))),
    ];
}

/* Address space a downloaded range may not reach into: what isPublicIpv4() turns
   away (private, reserved and carrier-grade NAT space) and multicast. Blocking any
   of it would cut a device off its own network, the firewall, or its own host.
   The documentation blocks route nowhere, so blocking one costs nothing. */
const NON_PUBLIC_RANGES = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
    '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/4', '240.0.0.0/4'];

/**
 * Does the IPv4 range $cidr (or a bare address) overlap NON_PUBLIC_RANGES? Two
 * ranges overlap when either holds the other's first address.
 */
function reachesNonPublic(string $cidr): bool
{
    foreach (NON_PUBLIC_RANGES as $range) {
        if (ipv4InCidr(explode('/', $cidr)[0], $range) || ipv4InCidr(explode('/', $range)[0], $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * The valid IPv4 ranges in a downloaded list: unique, in input order.
 *
 * One range per line. Blanks, # comments, IPv6 and anything else are skipped, so
 * a list that grows other kinds of line still parses, and so is a range wider than
 * a /8, which no one service owns, and one that reaches into a device's own network
 * (reachesNonPublic()). A page with markup in it is not a list at all - an error
 * page can have a line that looks like an address - so any '<' discards the whole
 * text.
 *
 * @return list<string>
 */
function parseRanges(string $text): array
{
    if (str_contains($text, '<')) {
        return [];
    }
    $ranges = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if (isIpv4Cidr($line) && (int)(explode('/', $line)[1] ?? 32) >= 8 && !reachesNonPublic($line)) {
            $ranges[$line] = true;
        }
    }
    return array_keys($ranges);
}

/**
 * Split this run's DNS answers into the addresses to learn and the ones to leave
 * alone.
 *
 * An address that a protected name resolved to this run is never kept: blocking
 * it would take that name down too. The shared-CDN ranges catch the rest, since
 * one address there serves thousands of unrelated sites. An address that is both
 * is dropped either way; protected is tested first, which only decides the reason
 * reported for it.
 *
 * @param array<string> $addrs          IPv4 candidates (resolveAll's addresses)
 * @param array<string> $protectedAddrs what the protected names resolved to
 * @param array<string> $cdnRanges      IPv4 CIDRs of shared CDNs
 * @return array{kept: list<string>, dropped: array<string, string>} kept is
 *         sorted and unique; dropped maps address to 'protected' or 'shared
 *         CDN', in address order
 */
function filterCandidates(array $addrs, array $protectedAddrs, array $cdnRanges): array
{
    $kept = [];
    $dropped = [];
    foreach ($addrs as $ip) {
        if (in_array($ip, $protectedAddrs, true)) {
            $dropped[$ip] = 'protected';
        } elseif (ipv4InAny($ip, $cdnRanges)) {
            $dropped[$ip] = 'shared CDN';
        } else {
            $kept[] = $ip;
        }
    }
    $kept = array_unique($kept);
    sort($kept, SORT_STRING);
    ksort($dropped, SORT_STRING);
    return ['kept' => $kept, 'dropped' => $dropped];
}

/**
 * Fold the addresses seen this run into the learned set.
 *
 * Every fresh address is stamped $now, which also refreshes one already known,
 * and any address last seen before $now - $maxAge is forgotten. The apps' names
 * rotate through pools of short-lived addresses, so remembering them for a while
 * is what keeps the block in force between lookups. Sorted by address, so the
 * same set always comes out the same.
 *
 * @param array<string, int> $learned address => time last seen
 * @param array<string>      $fresh   IPv4 addresses seen this run
 * @return array<string, int>
 */
function mergeLearned(array $learned, array $fresh, int $now, int $maxAge): array
{
    foreach ($fresh as $ip) {
        $learned[$ip] = $now;
    }
    $learned = array_filter($learned, fn(int $seen): bool => $seen >= $now - $maxAge);
    ksort($learned, SORT_STRING);
    return $learned;
}

/**
 * The published ranges to use for one app: a fresh download if it produced any,
 * else the last good one, else the copy shipped with the plugin. A download that
 * came back empty (an error page, an empty body, IPv6 only) must never replace a
 * working list.
 *
 * @param array<string> $fresh    parseRanges() of a download made this run, or []
 * @param array<string> $lastGood the last download that had any ranges, or []
 * @param array<string> $shipped  the ranges that ship with the plugin
 * @return array<string>
 */
function choosePublished(array $fresh, array $lastGood, array $shipped): array
{
    return $fresh ?: $lastGood ?: $shipped;
}

/**
 * The apps' published ranges that may be blocked, and the ranges that hold a
 * protected address.
 *
 * The guard on learned addresses (filterCandidates()) does not look inside a range, so
 * this does. An app's range that holds an address a protected name resolves to is left
 * out: blocking it would take that name down too. A notification range that holds one
 * is kept, because denying messaging always cuts notifications, and is only reported.
 * One error per range, naming the first protected address it holds.
 *
 * @param array<string, array<string>> $appRanges    app id => the ranges chosen for it
 * @param array<string>                $notifyRanges the notification services' ranges
 * @param array<string>                $protected    the protected addresses (protectedGuard())
 * @return array{ranges: list<string>, errors: list<string>} the app ranges to block, in
 *         input order, and the errors, the apps' first
 */
function filterRanges(array $appRanges, array $notifyRanges, array $protected): array
{
    $held = function (string $range) use ($protected): ?string {
        foreach ($protected as $ip) {
            if (ipv4InCidr($ip, $range)) {
                return $ip;
            }
        }
        return null;
    };
    $ranges = [];
    $errors = [];
    foreach ($appRanges as $app => $list) {
        foreach ($list as $range) {
            $ip = $held($range);
            if ($ip === null) {
                $ranges[] = $range;
            } else {
                $errors[] = "range $range of $app holds protected address $ip; not blocked";
            }
        }
    }
    foreach ($notifyRanges as $range) {
        $ip = $held($range);
        if ($ip !== null) {
            $errors[] = "notification range $range holds protected address $ip";
        }
    }
    return ['ranges' => $ranges, 'errors' => $errors];
}

/**
 * Every destination a device denied messaging may not reach: the learned
 * addresses, the published ranges and the notification services' ranges.
 *
 * The notification ranges go in even when everything else is empty, because
 * denying messaging always cuts notifications, whichever apps are selected.
 * Sorted and unique, so the same set always comes out the same.
 *
 * @param array<string, int> $learned         address => time last seen
 * @param array<string>      $published       the chosen CIDRs of the selected apps
 * @param array<string>      $appleRanges     CIDRs
 * @param array<string>      $microsoftRanges CIDRs
 * @return list<string>
 */
function destinationSet(array $learned, array $published, array $appleRanges, array $microsoftRanges): array
{
    $all = array_unique(array_merge(array_keys($learned), $published, $appleRanges, $microsoftRanges));
    sort($all, SORT_STRING);
    return $all;
}

/**
 * Resolve host names to IPv4 addresses, giving up when the time budget is spent.
 *
 * A slow or dead resolver must not hold up the minute's sync, so the budget is
 * checked before each lookup and, once it is reached, every host not yet tried is
 * skipped rather than waited for. The lookup and the clock are arguments so a
 * test can fake both. A lookup that returns an empty list found no addresses; it
 * did not fail.
 *
 * @param array<string>                          $hosts
 * @param callable(string): (list<string>|false) $lookup one host's addresses, or
 *        false when the lookup itself failed
 * @param callable(): int                        $nowMs milliseconds from any origin
 * @return array{addresses: list<string>, skipped: list<string>, failed: list<string>, answers: array<string, list<string>>}
 *         addresses are IPv4 only, sorted and unique; skipped ran out of time;
 *         failed returned false; answers holds each other host's own addresses,
 *         the same way, possibly none
 */
function resolveAll(array $hosts, callable $lookup, int $budgetMs, callable $nowMs): array
{
    $start = $nowMs();
    $addresses = [];
    $skipped = [];
    $failed = [];
    $answers = [];
    $spent = false;
    foreach ($hosts as $host) {
        $spent = $spent || ($nowMs() - $start >= $budgetMs);
        if ($spent) {
            $skipped[] = $host;
            continue;
        }
        $found = $lookup($host);
        if ($found === false) {
            $failed[] = $host;
            continue;
        }
        $own = [];
        foreach ($found as $ip) {
            if (isIpv4($ip)) {
                $own[] = $ip;
            }
        }
        $own = array_unique($own);
        sort($own, SORT_STRING);
        $answers[$host] = $own;
        $addresses = array_merge($addresses, $own);
    }
    $addresses = array_unique($addresses);
    sort($addresses, SORT_STRING);
    return ['addresses' => $addresses, 'skipped' => $skipped, 'failed' => $failed, 'answers' => $answers];
}

/**
 * The addresses in one name's dns_get_record() answer that may be learned: the
 * public IPv4 ones (isPublicIpv4()), in answer order. A record without one is
 * skipped.
 *
 * @param array<mixed> $records
 * @return list<string>
 */
function answerAddresses(array $records): array
{
    $addresses = [];
    foreach ($records as $record) {
        $ip = is_array($record) ? ($record['ip'] ?? null) : null;
        if (is_string($ip) && isPublicIpv4($ip)) {
            $addresses[] = $ip;
        }
    }
    return $addresses;
}

/**
 * The names to resolve: the notification services' names, then the selected apps'
 * names taking turns (each app's first name, then each app's second, and so on),
 * each once (where a name comes twice, the first place counts).
 *
 * resolveAll() spends its time budget in this order, the same every run, so a slow
 * resolver skips the names at the end. The notification names come first because
 * denying messaging cuts notifications whichever apps are selected, even none. The
 * apps take turns so that one long list cannot push another app's names to the end.
 *
 * @param array<array{hosts: list<string>}> $apps the selected apps' entries in
 *        messaging.json
 * @param array{google_hosts: list<string>, microsoft_hosts: list<string>} $notifications
 *        messaging.json's notifications
 * @return list<string>
 */
function messagingHosts(array $apps, array $notifications): array
{
    $hosts = array_merge($notifications['google_hosts'], $notifications['microsoft_hosts']);
    $lists = array_values(array_map(fn(array $app): array => array_values($app['hosts']), $apps));
    $turns = max(array_map('count', $lists) ?: [0]);
    for ($i = 0; $i < $turns; $i++) {
        foreach ($lists as $list) {
            if (isset($list[$i])) {
                $hosts[] = $list[$i];
            }
        }
    }
    return array_values(array_unique($hosts));
}

/**
 * The protected names that count this run: a name with no owner always, and a name
 * owned by an app only while that app is not selected, because a selected app's own
 * servers are what it blocks.
 *
 * @param list<string>          $hosts    messaging.json's protected_hosts
 * @param array<string, string> $owners   messaging.json's protected_owners: host => app id
 * @param array<string>         $selected this run's selected app ids
 * @return list<string> the hosts that count, in their order
 */
function protectedNames(array $hosts, array $owners, array $selected): array
{
    return array_values(array_filter($hosts,
        fn(string $host): bool => !in_array($owners[$host] ?? null, $selected, true)));
}

/**
 * The learned set after this run.
 *
 * The cached addresses go through filterCandidates() again, with the protected
 * addresses this run knows, and whatever it drops leaves the set: the guard can
 * change under an address learned earlier, as when a protected name starts
 * resolving to it or a plugin update widens the CDN list, and a protected address
 * must not stay blocked for the week a learned address lives.
 *
 * This run's answers that pass the same filter are learned (mergeLearned()), but
 * only when no protected name is missing. A protected name with no answer, this run
 * or a recent one, may share an address with an app, and nothing tells which: then
 * nothing new is learned and nothing is stamped again, while addresses not seen
 * for the maximum age still expire.
 *
 * @param array<string, int> $learned address => time last seen, from the cache
 * @param array<string>      $fresh   this run's answers
 * @param array{addresses: list<string>, missing: list<string>} $guard protectedGuard()
 * @param array<string>      $cdnRanges IPv4 CIDRs of shared CDNs
 * @return array<string, int>
 */
function updateLearned(array $learned, array $fresh, array $guard, array $cdnRanges, int $now, int $maxAge): array
{
    $dropped = filterCandidates(array_keys($learned), $guard['addresses'], $cdnRanges)['dropped'];
    $kept = $guard['missing'] === [] ? filterCandidates($fresh, $guard['addresses'], $cdnRanges)['kept'] : [];
    return mergeLearned(array_diff_key($learned, $dropped), $kept, $now, $maxAge);
}

/**
 * The protected addresses to filter by this run, and the answers to keep.
 *
 * A protected name answered this run, even with nothing, replaces what was kept for
 * it. One that failed or ran out of time falls back to its kept answer while that is
 * at most $maxAge seconds old. A name with neither is missing: its addresses are
 * unknown, so updateLearned() learns nothing new this run. A name no longer
 * protected is forgotten.
 *
 * @param list<string>                $names   the protected names
 * @param array<string, list<string>> $answers resolveAll()'s answers for them
 * @param array<string, array{addresses: list<string>, seen: int}> $memory the kept answers
 * @return array{addresses: list<string>, missing: list<string>, memory: array<string, array{addresses: list<string>, seen: int}>}
 *         the addresses to filter by, sorted and unique; the names with no answer,
 *         in list order; and the answers to keep
 */
function protectedGuard(array $names, array $answers, array $memory, int $now, int $maxAge): array
{
    $addresses = [];
    $missing = [];
    $kept = [];
    foreach ($names as $name) {
        $entry = isset($answers[$name]) ? ['addresses' => $answers[$name], 'seen' => $now] : ($memory[$name] ?? null);
        if ($entry !== null) {
            $kept[$name] = $entry;
        }
        if ($entry === null || $entry['seen'] < $now - $maxAge) {
            $missing[] = $name;
        } else {
            $addresses = array_merge($addresses, $entry['addresses']);
        }
    }
    $addresses = array_unique($addresses);
    sort($addresses, SORT_STRING);
    return ['addresses' => $addresses, 'missing' => $missing, 'memory' => $kept];
}

/**
 * The learned set to start this run from: empty when an app selected at the last
 * refresh is no longer selected, else as it was.
 *
 * The set does not record which app an address came from, so taking an app's
 * servers out means learning again from nothing; the names still selected are
 * looked up again in the same run. Adding an app, or a cache from before the
 * selection was kept, changes nothing.
 *
 * @param array<string, int> $learned  address => time last seen
 * @param ?array<string>     $lastApps the app ids of the last refresh, null when unknown
 * @param array<string>      $apps     this run's app ids
 * @return array<string, int>
 */
function learnedForSelection(array $learned, ?array $lastApps, array $apps): array
{
    return $lastApps !== null && array_diff($lastApps, $apps) !== [] ? [] : $learned;
}

/**
 * Is an app's published list due for download? When it never was, or its last
 * good copy is more than $maxAge seconds old; but never within $retryAfter seconds
 * of a failed download, so a list that cannot be fetched is not tried every run.
 *
 * @param ?array{ranges: list<string>, fetched_at: int, failed_at?: int} $entry the
 *        app's entry in the cache's downloads, null when it has none
 */
function rangesDue(?array $entry, int $now, int $maxAge, int $retryAfter): bool
{
    return $entry === null
        || ($now - $entry['fetched_at'] > $maxAge
            && (!isset($entry['failed_at']) || $now - $entry['failed_at'] >= $retryAfter));
}

/**
 * The selected apps' published ranges, downloading each app's list when
 * rangesDue() says so.
 *
 * A download that yields no ranges (an error, an error page, an empty body) has
 * failed: the entry keeps its last good copy and that copy's time, and records when
 * it failed, which holds the next try back. A good download replaces the entry,
 * failure and all. Each app's ranges are choosePublished() of this run's download,
 * the last good one and the shipped copy, plus its extra ranges and its per-site
 * ranges, each range once: ranges the app's own list leaves out, and the addresses
 * of its servers at every site of its operator, both shipped with the plugin. An
 * app with no ranges_url uses its shipped ranges only, never a copy the cache kept
 * from a version that had one.
 *
 * @param array<string, array{ranges_url?: ?string, ranges?: list<string>, extra_ranges?: list<string>, site_ranges?: list<string>}> $apps the
 *        selected apps' entries in messaging.json, by id
 * @param array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}> $published
 *        the cache's downloads, by app id; until one works, fetched_at is 0 and
 *        ranges is empty
 * @param callable(string): (string|false) $fetch the body at a URL, or false when
 *        the download failed
 * @return array{ranges: array<string, array<string>>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, failed: list<string>}
 *         each app's ranges, by id, the downloads after this run, and the ids of the
 *         apps whose last download failed
 */
function refreshPublished(
    array $apps,
    array $published,
    int $now,
    int $maxAge,
    int $retryAfter,
    callable $fetch
): array {
    $ranges = [];
    $failed = [];
    foreach ($apps as $id => $app) {
        $url = (string)($app['ranges_url'] ?? '');
        $fresh = [];
        $lastGood = [];
        if ($url !== '') {
            $entry = $published[$id] ?? null;
            if (rangesDue($entry, $now, $maxAge, $retryAfter)) {
                $body = $fetch($url);
                $fresh = $body === false ? [] : parseRanges($body);
                $entry = $fresh === []
                    ? ['ranges' => $entry['ranges'] ?? [], 'fetched_at' => $entry['fetched_at'] ?? 0,
                       'failed_at' => $now]
                    : ['ranges' => $fresh, 'fetched_at' => $now];
                $published[$id] = $entry;
            }
            if (isset($entry['failed_at'])) {
                $failed[] = (string)$id;
            }
            $lastGood = $entry['ranges'] ?? [];
        }
        $ranges[$id] = array_values(array_unique(array_merge(
            choosePublished($fresh, $lastGood, $app['ranges'] ?? []),
            $app['extra_ranges'] ?? [],
            $app['site_ranges'] ?? []
        )));
    }
    return ['ranges' => $ranges, 'published' => $published, 'failed' => $failed];
}

/**
 * The cache file's content, keeping only what has the shape sync.php writes.
 *
 * The file is the plugin's own, but a damaged copy must neither put a wrong entry
 * in a pf table nor stop the refresh, so each entry is judged on its own and a bad
 * one is dropped, as if it had never been there: a learned entry needs a public
 * IPv4 address (isPublicIpv4()) and an integer time; a published one a list of IPv4
 * ranges, an integer time and, if it has one, an integer failure time; a protected
 * name's a list of public IPv4 addresses and an integer time. The apps of the last
 * refresh must be a list of ids, or they are unknown (null). Anything but an array
 * is an empty cache.
 *
 * @param mixed $raw the file's JSON, decoded to arrays
 * @return array{learned: array<string, int>, published: array<string, array{ranges: list<string>, fetched_at: int, failed_at?: int}>, protected: array<string, array{addresses: list<string>, seen: int}>, apps: ?list<string>, refreshed_at: ?int, errors: list<string>}
 */
function sanitizeCache(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $learned = [];
    foreach ((array)($raw['learned'] ?? []) as $ip => $seen) {
        if (isPublicIpv4((string)$ip) && is_int($seen)) {
            $learned[$ip] = $seen;
        }
    }
    $protected = [];
    foreach ((array)($raw['protected'] ?? []) as $name => $entry) {
        if (!is_array($entry) || !is_int($entry['seen'] ?? null) || !is_array($entry['addresses'] ?? null)
            || !array_is_list($entry['addresses'])) {
            continue;
        }
        $addresses = array_filter($entry['addresses'], fn($a): bool => is_string($a) && isPublicIpv4($a));
        if ($addresses === $entry['addresses']) {
            $protected[$name] = ['addresses' => $addresses, 'seen' => $entry['seen']];
        }
    }
    $apps = $raw['apps'] ?? null;
    $apps = is_array($apps) && array_is_list($apps) && $apps === array_filter($apps, 'is_string') ? $apps : null;
    $published = [];
    foreach ((array)($raw['published'] ?? []) as $id => $entry) {
        $failedAt = is_array($entry) ? ($entry['failed_at'] ?? null) : null;
        if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null) || !is_array($entry['ranges'] ?? null)
            || !array_is_list($entry['ranges']) || ($failedAt !== null && !is_int($failedAt))) {
            continue;
        }
        $ranges = array_filter($entry['ranges'], fn($r): bool => is_string($r) && isIpv4Cidr($r));
        if ($ranges === $entry['ranges']) {
            $published[$id] = ['ranges' => $ranges, 'fetched_at' => $entry['fetched_at']]
                + ($failedAt === null ? [] : ['failed_at' => $failedAt]);
        }
    }
    $errors = $raw['errors'] ?? [];
    return [
        'learned' => $learned,
        'published' => $published,
        'protected' => $protected,
        'apps' => $apps,
        'refreshed_at' => is_int($raw['refreshed_at'] ?? null) ? $raw['refreshed_at'] : null,
        'errors' => is_array($errors) ? array_values(array_filter($errors, 'is_string')) : [],
    ];
}

/**
 * What a refresh reports, worded as status shows it: first that learning is paused,
 * naming the protected names with no answer, since status shows only the first few;
 * then, for each lookup run in the order given, the names that failed and those that
 * ran out of time; then each app whose list download failed.
 *
 * @param list<array{failed: list<string>, skipped: list<string>}> $lookups resolveAll() results
 * @param array<string> $failedDownloads app ids
 * @param array<string> $unanswered      protectedGuard()'s missing names
 * @return list<string>
 */
function refreshErrors(array $lookups, array $failedDownloads, array $unanswered): array
{
    $errors = $unanswered === [] ? [] : ['learning paused: no answer for ' . implode(', ', $unanswered)];
    foreach ($lookups as $result) {
        foreach ($result['failed'] as $host) {
            $errors[] = "lookup failed: $host";
        }
        foreach ($result['skipped'] as $host) {
            $errors[] = "lookup skipped (time budget): $host";
        }
    }
    foreach ($failedDownloads as $app) {
        $errors[] = "range download failed: $app";
    }
    return $errors;
}

/**
 * The ids of the open connections to kill when a device starts being denied
 * messaging: those going to one of $destinations, and those to a TCP port in
 * $portLo..$portHi (a notification service holds one long-lived connection per
 * device, to any address).
 *
 * Nothing else is selected, so the device's other connections, and every other
 * device's connections to the same servers, are left alone. A range with
 * $portLo > $portHi holds no port, which is how a caller asks for addresses only.
 * A row missing any key this reads is ignored: a state that cannot be read in full
 * is not killed on a guess. Single addresses (bare or /32), thousands of them for
 * the per-site servers, are looked up; only real networks are scanned.
 *
 * @param list<array<string, string>> $records      rows of core's `filter list
 *        states` (its "details"); only id, proto, dst_addr and dst_port are read
 * @param array<string>               $destinations IPv4 addresses or CIDRs
 * @return list<string> the ids ("hexid/hexcreator"), unique, in input order
 */
function selectStatesToDrop(array $records, array $destinations, int $portLo, int $portHi): array
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
    $ids = [];
    foreach ($records as $row) {
        if (!isset($row['id'], $row['proto'], $row['dst_addr'], $row['dst_port'])) {
            continue;
        }
        $port = (int)$row['dst_port'];
        if (isset($hosts[$row['dst_addr']]) || ipv4InAny($row['dst_addr'], $networks)
            || (strtolower($row['proto']) === 'tcp' && $port >= $portLo && $port <= $portHi)) {
            $ids[] = $row['id'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Which open connections of one device in the device table to drop this run, as
 * selectStatesToDrop()'s destinations and port range; null when none.
 *
 * A device that entered the table this run loses its connections to every server in
 * $dest and to the notification ports $portLo..$portHi. One that was there already
 * loses only those to the servers added this run (1..0 holds no port), and nothing
 * when none were added.
 *
 * @param array<string> $added     the device-table entries added this run
 * @param array<string> $dest      this run's servers
 * @param array<string> $addedDest the servers added this run
 * @return ?array{destinations: array<string>, portLo: int, portHi: int}
 */
function dropPlan(string $target, array $added, array $dest, array $addedDest, int $portLo, int $portHi): ?array
{
    if (in_array($target, $added, true)) {
        return ['destinations' => $dest, 'portLo' => $portLo, 'portHi' => $portHi];
    }
    return $addedDest === [] ? null : ['destinations' => $addedDest, 'portLo' => 1, 'portHi' => 0];
}

/**
 * Did `filter kill state` run? pfctl answers "killed N states ...", whatever N is;
 * anything else, an empty answer from configd included, is a failure to report.
 */
function stateKilled(string $response): bool
{
    return str_starts_with($response, 'killed');
}

/**
 * The wanted alias names that an alias of someone else's already holds.
 *
 * The plugin's aliases have fixed names, and a user may have made one first; the
 * plugin must not take over what it did not make. An alias is the plugin's when its
 * description starts with the marker for its name, the test the rest of the plugin
 * uses (lib/markers.php): any other alias with a wanted name is a conflict, and an
 * alias with any other name is not this function's business.
 *
 * @param list<array<string, string>> $aliases every alias in the firewall, as
 *        ['name' => ..., 'description' => ...]
 * @param array<string, string>       $wanted  name => description marker
 * @return list<string> the conflicting names, in alias order
 */
function aliasConflicts(array $aliases, array $wanted): array
{
    $conflicts = [];
    foreach ($aliases as $alias) {
        $marker = $wanted[$alias['name']] ?? null;
        if ($marker !== null && !str_starts_with($alias['description'], $marker)) {
            $conflicts[] = $alias['name'];
        }
    }
    return $conflicts;
}

/* findForeignReferences()'s one location when the configuration cannot be parsed */
const CONFIG_UNPARSED = 'config.xml could not be parsed';

/**
 * Where, outside the plugin's own objects, the firewall's configuration mentions
 * one of the plugin's aliases: the places uninstall must not leave dangling.
 *
 * Each element is judged on its own text and its own attribute values, not its
 * children's, so a hit is reported once, at the element that holds it. A name counts
 * as a whole word only: it must not touch a letter, digit, '_', '.' or '-', so
 * 'NoMessagingOld' is not 'NoMessaging'. An empty name is ignored. The plugin's own
 * objects are skipped with everything inside them: an element whose uuid attribute is
 * in $ownUuids, and the OPNsense/ParentalControl subtree that holds its settings. So
 * is the top-level revision element: it records the last save, whose description may
 * name an alias without referring to it. The root element itself is not examined.
 *
 * A location is the element's path below the root, joined with '/', then ' (uuid)'
 * for the nearest ancestor-or-self that has a uuid attribute, as in
 * 'OPNsense/Firewall/Filter/rules/rule/source_net (user-1)'. One per element, in
 * document order.
 *
 * A configuration that cannot be parsed gives the single location CONFIG_UNPARSED,
 * so the caller refuses instead of going on blind. Whatever the text, parsing neither
 * throws nor warns, and libxml's error mode is left as it was found. Text the pattern
 * cannot be run on counts as a hit, for the same reason.
 *
 * @param string        $configXml the whole of /conf/config.xml, root <opnsense>
 * @param array<string> $ownUuids  uuids of the plugin's own aliases, rules and cron job
 * @param array<string> $names     the plugin's alias names
 * @return list<string> locations
 */
function findForeignReferences(string $configXml, array $ownUuids, array $names): array
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    /* loadXML() throws, rather than failing, on an empty string */
    $loaded = $configXml !== '' && $doc->loadXML($configXml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $loaded ? $doc->documentElement : null;
    if ($root === null) {
        return [CONFIG_UNPARSED];
    }
    /* with no names, or an empty one, the pattern below would match any run of
       non-word characters */
    $names = array_diff($names, ['']);
    if ($names === []) {
        return [];
    }

    $quoted = array_map(fn(string $n): string => preg_quote($n, '/'), $names);
    $word = '/(?<![\w.-])(?:' . implode('|', $quoted) . ')(?![\w.-])/';
    $found = [];
    $walk = function (
        DOMElement $el,
        string $path,
        string $uuid
    ) use (&$walk, &$found, $word, $ownUuids): void {
        $path .= ($path === '' ? '' : '/') . $el->nodeName;
        $own = $el->getAttribute('uuid');
        /* an empty uuid is no uuid: it must not match an empty entry in $ownUuids */
        $isOwn = $own !== '' && in_array($own, $ownUuids, true);
        if ($isOwn || $path === 'OPNsense/ParentalControl' || $path === 'revision') {
            return;
        }
        $uuid = $own !== '' ? $own : $uuid;

        $strings = [];
        foreach ($el->attributes as $attribute) {
            $strings[] = $attribute->value;
        }
        $children = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMText) {
                $strings[] = $child->data;
            } elseif ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        /* preg_match() gives false when it fails, which is not the same as no match:
           count it as a hit, so uninstall refuses rather than passing text it could
           not check */
        if (array_filter($strings, fn(string $s): bool => preg_match($word, $s) !== 0)) {
            $found[] = $path . ($uuid !== '' ? " ($uuid)" : '');
        }
        foreach ($children as $child) {
            $walk($child, $path, $uuid);
        }
    };
    foreach ($root->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $walk($child, '', '');
        }
    }
    return $found;
}
