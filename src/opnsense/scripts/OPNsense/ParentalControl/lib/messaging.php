<?php

/**
 * Pure functions for the messaging block: the per-device decision and the IPv4
 * helpers.
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
 * '010.0.0.1' as 10.0.0.1 while inet_aton reads it as 8.0.0.1, and one range
 * meaning two networks is how the wrong thing gets blocked.
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
