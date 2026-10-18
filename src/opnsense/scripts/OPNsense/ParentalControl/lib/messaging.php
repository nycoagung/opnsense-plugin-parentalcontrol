<?php

/**
 * Pure functions for the messaging block: the per-device decision, the IPv4
 * helpers, the builders of the server list, the choice of connections to drop and
 * the two checks setup and uninstall make before touching an alias.
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
            if (isIpv4($ip)) {
                $addresses[] = $ip;
            }
        }
    }
    $addresses = array_unique($addresses);
    sort($addresses, SORT_STRING);
    return ['addresses' => $addresses, 'skipped' => $skipped, 'failed' => $failed];
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
 * is not killed on a guess.
 *
 * @param list<array<string, string>> $records      rows of core's `filter list
 *        states` (its "details"); only id, proto, dst_addr and dst_port are read
 * @param array<string>               $destinations IPv4 addresses or CIDRs
 * @return list<string> the ids ("hexid/hexcreator"), unique, in input order
 */
function selectStatesToDrop(array $records, array $destinations, int $portLo, int $portHi): array
{
    $ids = [];
    foreach ($records as $row) {
        if (!isset($row['id'], $row['proto'], $row['dst_addr'], $row['dst_port'])) {
            continue;
        }
        $port = (int)$row['dst_port'];
        if (ipv4InAny($row['dst_addr'], $destinations)
            || (strtolower($row['proto']) === 'tcp' && $port >= $portLo && $port <= $portHi)) {
            $ids[] = $row['id'];
        }
    }
    return array_values(array_unique($ids));
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

/**
 * Where, outside the plugin's own objects, the firewall's configuration mentions
 * one of the plugin's aliases: the places uninstall must not leave dangling.
 *
 * Each element is judged on its own text and its own attribute values, not its
 * children's, so a hit is reported once, at the element that holds it. A name counts
 * as a whole word only: it must not touch a letter, digit, '_', '.' or '-', so
 * 'NoMessagingOld' is not 'NoMessaging'. The plugin's own objects are skipped with
 * everything inside them: an element whose uuid attribute is in $ownUuids, and the
 * OPNsense/ParentalControl subtree that holds its settings. The root element itself
 * is not examined.
 *
 * A location is the element's path below the root, joined with '/', then ' (uuid)'
 * for the nearest ancestor-or-self that has a uuid attribute, as in
 * 'OPNsense/Firewall/Filter/rules/rule/source_net (user-1)'. One per element, in
 * document order.
 *
 * A configuration that cannot be parsed gives the single location 'config.xml could
 * not be parsed', so the caller refuses instead of going on blind. Whatever the
 * text, parsing neither throws nor warns, and libxml's error mode is left as it was
 * found. Text the pattern cannot be run on counts as a hit, for the same reason.
 *
 * @param string        $configXml the whole of /conf/config.xml, root <opnsense>
 * @param array<string> $ownUuids  uuids of the plugin's own aliases and rules
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
        return ['config.xml could not be parsed'];
    }
    /* with no names the pattern below would match any run of non-word characters */
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
        if ($isOwn || $path === 'OPNsense/ParentalControl') {
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
