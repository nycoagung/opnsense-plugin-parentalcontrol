<?php

/**
 * Pure functions for the messaging block: the per-device decision, the IPv4
 * helpers and the builders of the server list.
 *
 * No I/O, no side effects and no OPNsense classes, so this file loads anywhere
 * PHP runs: on the firewall from sync.php, and from tests/MessagingTest.php on
 * any machine. Everything that touches the firewall stays in sync.php.
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
 * A bare IPv4 address, or a.b.c.d/n with n from 0 to 32.
 *
 * The address goes through filter_var, not ip2long(): ip2long() reads
 * '192.0.2.010' as 192.0.2.10 while inet_aton reads it as 192.0.2.8, and one
 * range meaning two networks is how the wrong thing gets blocked.
 */
function isIpv4Cidr(string $s): bool
{
    $p = explode('/', $s);
    return count($p) <= 2
        && filter_var($p[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && (count($p) === 1 || (ctype_digit($p[1]) && (int)$p[1] <= 32));
}

/**
 * Is $ip inside $cidr? A bare address as $cidr is a /32. Anything invalid, in
 * either argument, is false, so a bad range can never match.
 */
function ipv4InCidr(string $ip, string $cidr): bool
{
    if (!isIpv4Cidr($cidr) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
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
 * The valid IPv4 ranges in a downloaded list: unique, in input order.
 *
 * One range per line. Blanks, # comments, IPv6 and anything else are skipped, so
 * a list that grows other kinds of line still parses. A page with markup in it
 * is not a list at all - an error page can have a line that looks like an
 * address - so any '<' discards the whole text.
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
        if (isIpv4Cidr($line)) {
            $ranges[$line] = true;
        }
    }
    return array_keys($ranges);
}

/**
 * Split this run's DNS answers into the addresses to learn and the ones to leave
 * alone.
 *
 * An address that a protected name resolved to this run is never kept, even when
 * it also sits in a shared-CDN range: blocking it would take that name down too.
 * The shared-CDN ranges catch the rest, since one address there serves thousands
 * of unrelated sites.
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
 * @return array{addresses: list<string>, skipped: list<string>, failed: list<string>}
 *         addresses are IPv4 only, sorted and unique; skipped ran out of time;
 *         failed returned false
 */
function resolveAll(array $hosts, callable $lookup, int $budgetMs, callable $nowMs): array
{
    $start = $nowMs();
    $addresses = [];
    $skipped = [];
    $failed = [];
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
        foreach ($found as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $addresses[] = $ip;
            }
        }
    }
    $addresses = array_unique($addresses);
    sort($addresses, SORT_STRING);
    return ['addresses' => $addresses, 'skipped' => $skipped, 'failed' => $failed];
}
