#!/usr/local/bin/php
<?php

/**
 * Parental Control enforcement.
 *
 *   sync.php           resolve every device and apply the result
 *   sync.php status    resolve every device and print JSON, changing nothing
 *
 * One implementation of the schedule logic, shared by the settings page, the
 * dashboard widget and the cron run, so none of them can disagree about what
 * is actually enforced.
 *
 * Enforcement is a single externally-managed alias plus a single floating block
 * rule. Toggling a device is a pf table update, which takes effect immediately
 * and needs no ruleset reload. Per-device rules were rejected: they do not
 * scale, they apply slowly, and arbitrary per-device schedules would need one
 * rule per distinct schedule.
 */

require_once("config.inc");
require_once("util.inc");

use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Firewall\Alias;
use OPNsense\Cron\Cron;
use OPNsense\Firewall\Filter;
use OPNsense\ParentalControl\ParentalControl;

/* Private address space. This is the definition of "not the internet", not an
   assumption about this particular network, so it stays correct anywhere. */
const LOCAL_NETS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

/* PHP does not read /etc/localtime; without date.timezone it silently uses UTC,
   which would shift every window by the UTC offset while the UI agreed with
   itself. Take the timezone from the firewall's own configuration. */
$tz = (string)(Config::getInstance()->object()->system->timezone ?? '');
if ($tz !== '' && @timezone_open($tz) !== false) {
    date_default_timezone_set($tz);
}

$mode = isset($argv[1]) ? $argv[1] : 'sync';
$mdl = new ParentalControl();
$backend = new Backend();

$aliasName = trim((string)$mdl->general->alias_name);
if ($aliasName === '') {
    $aliasName = 'NoInternet';
}
$localAlias = $aliasName . '_Local';
$enabled = (string)$mdl->general->enabled === '1';

/**
 * Resolve one device to blocked/allowed plus a human reason.
 */
function resolveDevice($dev, $enabled)
{
    if (!$enabled) {
        return [false, 'plugin disabled'];
    }
    if ((string)$dev->enabled !== '1') {
        return [false, 'device disabled'];
    }
    $override = (string)$dev->override;
    if ($override === 'allow') {
        return [false, 'override: allow'];
    }
    if ($override === 'block') {
        return [true, 'override: block'];
    }
    $mode = (string)$dev->mode;
    if ($mode === 'allow') {
        return [false, 'always allowed'];
    }
    if ($mode === 'block') {
        return [true, 'always blocked'];
    }

    /* scheduled - decided by a pure function so it can be tested directly */
    return scheduleDecision(
        array_filter(explode(',', (string)$dev->weekdays)),
        (int)date('H') * 60 + (int)date('i'),
        timeToMinutes((string)$dev->allow_from),
        timeToMinutes((string)$dev->allow_to),
        strtolower(date('D')),
        strtolower(date('D', strtotime('-1 day')))
    );
}

function isMac($v)
{
    return (bool)preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', trim($v));
}

/**
 * MAC -> current IPv4 address(es).
 *
 * pf has no layer-2 matching, so a MAC can only ever be resolved to an address
 * and filtered on that. A device the firewall has not seen cannot be resolved -
 * it is also not using the internet at that moment, so nothing escapes, but the
 * status output says so rather than pretending the device is covered.
 *
 * ARP is the live truth and is tried first; DHCP leases cover a device that is
 * powered on but has aged out of the ARP cache.
 */
function macToAddresses($mac)
{
    static $arp = null;
    static $leases = null;
    $mac = strtolower(trim($mac));

    if ($arp === null) {
        $arp = [];
        $out = [];
        @exec('/usr/sbin/arp -an 2>/dev/null', $out);
        foreach ($out as $line) {
            /* ? (192.168.1.5) at 9c:e6:5e:d0:0d:41 on ue0 expires in 1200 seconds */
            if (preg_match('/\(([0-9.]+)\) at ([0-9a-fA-F:]{17})/', $line, $m)) {
                $arp[strtolower($m[2])][] = $m[1];
            }
        }
    }
    if (isset($arp[$mac])) {
        return array_values(array_unique($arp[$mac]));
    }

    if ($leases === null) {
        $leases = [];
        /* dnsmasq only. The ISC path was removed: its lease file is a block
           format, so a whitespace-split parser could never match a line of it,
           and pretending otherwise hid the gap. Kea is not handled either. */
        $path = '/var/db/dnsmasq.leases';
        if (is_readable($path)) {
            foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                /* <expiry> <mac> <ip> <hostname> <clientid> */
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 3
                    || !preg_match('/^[0-9a-fA-F:]{17}$/', $parts[1])
                    || !filter_var($parts[2], FILTER_VALIDATE_IP)) {
                    continue;
                }
                /* An expired lease can point a MAC at an address some OTHER
                   device now holds - blocking an innocent one. */
                $exp = (int)$parts[0];
                if ($exp > 0 && $exp < time()) {
                    continue;
                }
                $leases[strtolower($parts[1])][] = $parts[2];
            }
        }
    }
    return isset($leases[$mac]) ? array_values(array_unique($leases[$mac])) : [];
}

/**
 * The schedule is only ever re-evaluated when this script runs, so the cron
 * entry is not optional - without it the UI would show the right answer while
 * nothing actually changed. The plugin therefore owns the job: created when
 * enabled, disabled when the plugin is disabled.
 *
 * Only writes when something genuinely differs. This runs once a minute, and
 * saving the config every minute would bloat the revision history for nothing.
 */
function ensureCronJob($backend, $enabled)
{
    $cron = new Cron();
    $want = $enabled ? '1' : '0';
    $found = null;
    foreach ($cron->jobs->job->iterateItems() as $job) {
        if ((string)$job->origin === 'parentalcontrol'
            && (string)$job->command === 'parentalcontrol sync') {
            $found = $job;
            break;
        }
    }

    $changed = false;
    if ($found === null) {
        if (!$enabled) {
            return false;               /* nothing to create, nothing to disable */
        }
        $job = $cron->jobs->job->Add();
        $job->origin = 'parentalcontrol';
        $job->enabled = '1';
        $job->minutes = '*';
        $job->hours = '*';
        $job->days = '*';
        $job->months = '*';
        $job->weekdays = '*';
        $job->who = 'root';
        $job->command = 'parentalcontrol sync';
        $job->parameters = '';
        $job->description = 'Parental Control: evaluate device schedules';
        $changed = true;
    } elseif ((string)$found->enabled !== $want) {
        $found->enabled = $want;
        $changed = true;
    }

    if (!$changed) {
        return false;
    }
    $val = $cron->performValidation();
    if ($val->count() > 0) {
        foreach ($val->getMessages() as $msg) {
            fwrite(STDERR, "cron: " . $msg->getField() . ": " . $msg->getMessage() . "\n");
        }
        return false;
    }
    $cron->serializeToConfig();
    Config::getInstance()->save();
    /* 'cron restart' is what regenerates the crontab - core's own cron
       ServiceController calls exactly this. 'cron reconfigure' is not a
       registered action: it returns quietly and the crontab is never written,
       so the job sits in the config looking correct while never running. */
    $backend->configdRun('cron restart');
    return true;
}

function cronState()
{
    foreach ((new Cron())->jobs->job->iterateItems() as $job) {
        if ((string)$job->origin === 'parentalcontrol'
            && (string)$job->command === 'parentalcontrol sync') {
            return (string)$job->enabled === '1' ? 'enabled' : 'disabled';
        }
    }
    return 'absent';
}

/**
 * The block rule is IPv4 only. On a dual-stack firewall a blocked device keeps
 * full IPv6 internet and will prefer it, while every indicator says "blocked".
 * Detect it so the UI can say so rather than lying by omission.
 */
function ipv6Active()
{
    $out = [];
    @exec('/sbin/ifconfig -a 2>/dev/null', $out);
    foreach ($out as $line) {
        if (preg_match('/^\s+inet6\s+([0-9a-fA-F:]+)/', $line, $m)) {
            $a = strtolower($m[1]);
            if (strpos($a, 'fe80') !== 0 && $a !== '::1') {
                return true;
            }
        }
    }
    return false;
}

/**
 * Pure schedule decision. Takes plain values so it can be exercised without a
 * model, a firewall or a clock - see tests/ScheduleTest.php.
 *
 * @param array  $days      selected weekday abbreviations, e.g. ['mon','fri']
 * @param int    $nowMin    minutes since local midnight
 * @param ?int   $from      window start in minutes, null if unparseable
 * @param ?int   $to        window end in minutes, null if unparseable
 * @param string $today     today's weekday abbreviation
 * @param string $yesterday yesterday's weekday abbreviation
 * @return array [bool blocked, string reason]
 */
function scheduleDecision($days, $nowMin, $from, $to, $today, $yesterday)
{
    /* Fail CLOSED. A scheduled device whose times were cleared must not become
       permanently allowed - that is the wrong direction for a safety feature,
       and it would contradict the empty-weekday case below, which blocks. */
    if ($from === null || $to === null) {
        return [true, 'scheduled but no valid time window'];
    }
    if ($from == $to) {
        return [false, 'window covers the whole day'];
    }

    $crosses = $from > $to;
    $inWindow = $crosses
        ? ($nowMin >= $from || $nowMin < $to)
        : ($nowMin >= $from && $nowMin < $to);

    /* A window that crosses midnight belongs to the day it STARTED on:
       "Friday 21:00-07:00" means Friday night into Saturday morning. Testing
       today's weekday during the tail would block exactly the half the user
       cares about, and allow the wrong morning instead. */
    $owningDay = ($crosses && $nowMin < $to) ? $yesterday : $today;

    if (!in_array($owningDay, $days, true)) {
        return [true, 'outside scheduled days'];
    }
    return $inWindow
        ? [false, 'within allowed hours']
        : [true, 'outside allowed hours'];
}

function timeToMinutes($v)
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v, $m)) {
        return null;
    }
    return ((int)$m[1]) * 60 + ((int)$m[2]);
}

/* ---- resolve every device ------------------------------------------------ */

$devices = [];
$blockSet = [];
foreach ($mdl->devices->iterateItems() as $uuid => $dev) {
    list($blocked, $reason) = resolveDevice($dev, $enabled);
    $addr = trim((string)$dev->address);

    /* a MAC is an identity, not something pf can match - resolve it to whatever
       address the device is using right now */
    if (isMac($addr)) {
        $targets = macToAddresses($addr);
        $resolved = empty($targets) ? '' : implode(', ', $targets);
        if (empty($targets) && $blocked) {
            $reason .= ' (MAC not currently resolvable)';
        }
    } else {
        $targets = $addr === '' ? [] : [$addr];
        $resolved = $addr;
    }

    $devices[] = [
        'uuid' => $uuid,
        'name' => (string)$dev->name,
        'address' => $addr,
        'is_mac' => isMac($addr),
        'resolved' => $resolved,
        'mode' => (string)$dev->mode,
        'override' => (string)$dev->override,
        'enabled' => (string)$dev->enabled,
        'blocked' => $blocked,
        'reason' => $reason,
    ];
    if ($blocked) {
        foreach ($targets as $t) {
            $blockSet[$t] = true;
        }
    }
}

/* ---- status: report only ------------------------------------------------- */

/**
 * Current contents of the pf table.
 *
 * 'filter list table' answers {"items": [...]} - the payload is under 'items',
 * not at the top level, which is how core's own alias utility reads it. Getting
 * that wrong returns an empty list silently, which makes every address look
 * absent: nothing is ever removed from the table, so a device stays blocked
 * after its schedule reopens.
 */
function tableContents($backend, $alias)
{
    $raw = $backend->configdpRun('filter list table', [$alias]);
    $decoded = json_decode(trim((string)$raw), true);
    if (!is_array($decoded)) {
        return [];
    }
    $items = array_key_exists('items', $decoded) ? $decoded['items'] : $decoded;
    $out = [];
    foreach ((array)$items as $row) {
        if (is_array($row)) {
            if (isset($row['ip'])) {
                $out[] = $row['ip'];
            }
        } elseif (is_string($row) && $row !== '') {
            $out[] = $row;
        }
    }
    return $out;
}

if ($mode === 'status') {
    $current = tableContents($backend, $aliasName);
    echo json_encode([
        'enabled' => $enabled,
        'cron' => cronState(),
        'ipv6_active' => ipv6Active(),
        'alias' => $aliasName,
        'in_alias' => count($current),
        'alias_entries' => $current,
        'devices' => $devices,
    ]);
    exit(0);
}

/* ---- make sure the cron entry matches the plugin state ------------------- */

ensureCronJob($backend, $enabled);

/* ---- make sure the alias and rule exist ---------------------------------- */

$cfgChanged = false;
$aliasMdl = new Alias();

$haveBlock = false;
$haveLocal = false;
foreach ($aliasMdl->aliases->alias->iterateItems() as $item) {
    $n = (string)$item->name;
    if ($n === $aliasName) {
        $haveBlock = true;
    } elseif ($n === $localAlias) {
        $haveLocal = true;
    }
}

if (!$haveBlock) {
    $node = $aliasMdl->aliases->alias->Add();
    $node->name = $aliasName;
    $node->type = 'external';               /* contents managed here, not in config */
    $node->enabled = '1';
    $node->description = 'Parental Control: devices currently denied internet';
    $cfgChanged = true;
}
if (!$haveLocal) {
    $node = $aliasMdl->aliases->alias->Add();
    $node->name = $localAlias;
    $node->type = 'network';
    $node->enabled = '1';
    $node->content = implode("\n", LOCAL_NETS);
    $node->description = 'Parental Control: local networks (block destination is NOT this)';
    $cfgChanged = true;
}
if ($cfgChanged) {
    $val = $aliasMdl->performValidation();
    if ($val->count() == 0) {
        $aliasMdl->serializeToConfig();
        Config::getInstance()->save();
    } else {
        /* print what actually failed - a bare "validation failed" makes a first
           install impossible to diagnose without a shell */
        foreach ($val->getMessages() as $msg) {
            fwrite(STDERR, "alias: " . $msg->getField() . ": " . $msg->getMessage() . "\n");
        }
        exit(1);
    }
}

/* one floating block rule: source in the alias, destination NOT local.
   No interface is set, so it covers every interface including ones added
   later - nothing about this installation is assumed. */
$ruleDescr = 'Parental Control: block internet for ' . $aliasName;
$filterMdl = new Filter();
$haveRule = false;
foreach ($filterMdl->rules->rule->iterateItems() as $rule) {
    if ((string)$rule->description === $ruleDescr) {
        $haveRule = true;
        break;
    }
}
if (!$haveRule) {
    $rule = $filterMdl->rules->rule->Add();
    $rule->enabled = '1';
    $rule->action = 'block';
    $rule->quick = '1';
    $rule->direction = 'in';
    $rule->ipprotocol = 'inet';
    $rule->source_net = $aliasName;
    $rule->destination_net = $localAlias;
    $rule->destination_not = '1';
    $rule->description = $ruleDescr;
    $val = $filterMdl->performValidation();
    if ($val->count() == 0) {
        $filterMdl->serializeToConfig();
        Config::getInstance()->save();
        $cfgChanged = true;
    } else {
        foreach ($val->getMessages() as $msg) {
            fwrite(STDERR, "rule: " . $msg->getField() . ": " . $msg->getMessage() . "\n");
        }
        exit(1);
    }
}
if ($cfgChanged) {
    $backend->configdRun('filter reload');
}

/* ---- sync the table ------------------------------------------------------ */

$current = tableContents($backend, $aliasName);
$want = array_keys($blockSet);

$toAdd = array_diff($want, $current);
$toDel = array_diff($current, $want);

foreach ($toAdd as $addr) {
    $backend->configdpRun('filter add table', [$aliasName, $addr]);
}
foreach ($toDel as $addr) {
    $backend->configdpRun('filter delete table', [$aliasName, $addr]);
}

/* Established connections survive a new block, so a stream already running
   keeps going until it ends by itself. Dropping their states is what makes
   "off" mean off. Best effort: never fail the sync over it. */
if ((string)$mdl->general->kill_states === '1') {
    foreach ($toAdd as $addr) {
        /* pfctl -k takes a host. For a CIDR, explode()[0] is the network
           address - killing states for an address nobody holds. Skip those and
           say so rather than appearing to have done something. */
        if (strpos($addr, '/') !== false) {
            fwrite(STDERR, "not dropping states for network $addr (pfctl -k takes a host)\n");
            continue;
        }
        $out = [];
        $rc = 0;
        @exec('/sbin/pfctl -k ' . escapeshellarg($addr) . ' 2>&1', $out, $rc);
    }
}

printf("blocked=%d added=%d removed=%d\n", count($want), count($toAdd), count($toDel));
