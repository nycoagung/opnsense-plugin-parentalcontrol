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
 * The internet switch is a single externally-managed alias plus a single
 * floating block rule. Toggling a device is a pf table update, which takes
 * effect immediately and needs no ruleset reload. Per-device rules were
 * rejected: they do not scale, they apply slowly, and arbitrary per-device
 * schedules would need one rule per distinct schedule.
 *
 * Messaging is blocked the same way, with objects of its own made on first use:
 * a table of the devices denied messaging, a table of the servers they may not
 * reach, and two floating rules, one to those servers and one to the
 * notification ports.
 */

require_once("config.inc");
require_once("util.inc");

use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\FieldTypes\NetworkAliasField;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Firewall\Alias;
use OPNsense\Cron\Cron;
use OPNsense\Firewall\Filter;
use OPNsense\ParentalControl\ParentalControl;

/* Private address space. This is the definition of "not the internet", not an
   assumption about this particular network, so it stays correct anywhere. */
const LOCAL_NETS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

foreach (['markers.php', 'messaging.php', 'firewall.php', 'serverlist.php'] as $lib) {
    /* an older installer's file list leaves out the files added since */
    if (!is_file(__DIR__ . "/lib/$lib")) {
        fwrite(STDERR, "plugin files incomplete (missing lib/$lib): run 'configctl parentalcontrol install' again\n");
        exit(1);
    }
    require_once(__DIR__ . "/lib/$lib");
}

/* the messaging aliases' fixed names, each with the marker that makes it ours */
const MSG_ALIASES = [MSG_DEVICES_ALIAS => MARK_MSG_DEVICES, MSG_SERVERS_ALIAS => MARK_MSG_SERVERS];

/* the messaging rules' markers, each with [protocol, destination, destination port] */
const MSG_RULES = [
    MARK_MSG_RULE => ['any', MSG_SERVERS_ALIAS, ''],
    MARK_NOTIFY_RULE => ['TCP', 'any', NOTIFY_PORTS],
];

/* the server list's data, shipped beside this script, and what the refresh keeps */
const MSG_DATA_FILE = __DIR__ . '/messaging.json';
const MSG_CACHE_FILE = DATA_DIR . '/messaging-cache.json';
const MSG_DATA_MISSING = 'messaging data missing or unreadable';

$mode = isset($argv[1]) ? $argv[1] : 'sync';

/* One sync at a time: DNS lookups make a run's length depend on the network,
   and cron starts one every minute. Status only reads, so it never waits. */
if ($mode !== 'status') {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0700);         /* a run starting at the same moment may make it first */
    }
    $lock = @fopen(DATA_DIR . '/sync.lock', 'c');
    if ($lock === false) {
        fwrite(STDERR, "cannot open " . DATA_DIR . "/sync.lock\n");
        exit(1);
    }
    for ($waited = 0; !flock($lock, LOCK_EX | LOCK_NB); $waited++) {
        if ($waited >= 30) {
            fwrite(STDERR, "another sync is still running\n");
            exit(1);
        }
        sleep(1);
    }
    /* config.inc read config.xml when it was required, before the lock was
       held. Read it again, so this run builds its models from what the run
       before it saved, and from any change made in the GUI meanwhile, instead
       of saving an older copy over them. */
    Config::getInstance()->forceReload();
    /* Uninstall has removed everything, or is about to: put nothing back. Its
       marker stops a sync that waited for uninstall.php. uninstall.sh deletes this
       script before the marker, so a sync that waited longer finds it gone. */
    if (file_exists(UNINSTALL_MARKER) || !is_file(__FILE__)) {
        fwrite(STDERR, "uninstall in progress - nothing changed\n");
        exit(0);
    }
}

/* PHP does not read /etc/localtime; without date.timezone it silently uses UTC,
   which would shift every window by the UTC offset while the UI agreed with
   itself. Take the timezone from the firewall's own configuration (a SimpleXML
   node, not a model field, so a string cast is how its text is read). */
$tz = (string)(Config::getInstance()->object()->system->timezone ?? '');
if ($tz !== '' && @timezone_open($tz) !== false) {
    date_default_timezone_set($tz);
}

$mdl = new ParentalControl();
$backend = new Backend();

/* Model fields are objects whose value is the text stored in config.xml:
   BooleanFields hold '1' or '0'. Read them with getValue() and compare with
   isEqual(), which return real strings and booleans. Testing a field directly
   (if ($dev->enabled)) would always be true, because any object is. */
$aliasName = trim($mdl->general->alias_name->getValue());
if ($aliasName === '') {
    $aliasName = 'NoInternet';
}
$localAlias = $aliasName . '_Local';
$enabled = $mdl->general->enabled->isEqual('1');
/* the apps whose servers are blocked, one list for every device */
$msgApps = array_values(array_filter(explode(',', $mdl->general->messaging_apps->getValue())));

/**
 * Resolve one device to blocked/allowed plus a human reason.
 *
 * @param array{bool, string} $schedule deviceSchedule() for this device
 * @return array{bool, string} [blocked, reason]
 */
function resolveDevice(BaseField $dev, bool $enabled, array $schedule): array
{
    if (!$enabled) {
        return [false, 'plugin disabled'];
    }
    if (!$dev->enabled->isEqual('1')) {
        return [false, 'device disabled'];
    }
    if ($dev->override->isEqual('allow')) {
        return [false, 'override: allow'];
    }
    if ($dev->override->isEqual('block')) {
        return [true, 'override: block'];
    }
    if ($dev->mode->isEqual('allow')) {
        return [false, 'always allowed'];
    }
    if ($dev->mode->isEqual('block')) {
        return [true, 'always blocked'];
    }

    return $schedule;
}

/**
 * The device's schedule, evaluated now. Called once per device per run, and
 * that one result is handed to both the internet and the messaging decision,
 * so the two cannot disagree, even when a run straddles a minute boundary.
 *
 * @return array{bool, string} [blocked, reason]
 */
function deviceSchedule(BaseField $dev): array
{
    /* decided by a pure function so it can be tested directly */
    return scheduleDecision(
        array_filter(explode(',', $dev->weekdays->getValue())),
        (int)date('H') * 60 + (int)date('i'),
        timeToMinutes($dev->allow_from->getValue()),
        timeToMinutes($dev->allow_to->getValue()),
        strtolower(date('D')),
        strtolower(date('D', strtotime('-1 day')))
    );
}

function isMac(string $v): bool
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
 *
 * @return list<string> IPv4 addresses, empty if the MAC is not seen
 */
function macToAddresses(string $mac): array
{
    static $arp = null;
    static $leases = null;
    $mac = strtolower(trim($mac));

    if ($arp === null) {
        $arp = [];
        $out = [];
        @exec('/usr/sbin/arp -an 2>/dev/null', $out);
        foreach ($out as $line) {
            /* ? (192.168.1.10) at 00:00:5e:00:53:01 on igb1 expires in 1200 seconds */
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
                $parts = preg_split('/\s+/', trim($line)) ?: [];
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
function ensureCronJob(Backend $backend, bool $enabled): void
{
    $cron = new Cron();
    $want = $enabled ? '1' : '0';
    $found = null;
    foreach ($cron->jobs->job->iterateItems() as $job) {
        if ($job->origin->isEqual('parentalcontrol')
            && $job->command->isEqual('parentalcontrol sync')) {
            $found = $job;
            break;
        }
    }

    $changed = false;
    if ($found === null) {
        if (!$enabled) {
            return;                     /* nothing to create, nothing to disable */
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
        $job->description = CRON_DESCRIPTION;
        $changed = true;
    } elseif (!$found->enabled->isEqual($want)) {
        $found->enabled = $want;
        $changed = true;
    }

    if (!$changed || !saveModel($cron, 'cron')) {
        return;
    }
    /* 'cron restart' is what regenerates the crontab - core's own cron
       ServiceController calls exactly this. 'cron reconfigure' is not a
       registered action: it returns quietly and the crontab is never written,
       so the job sits in the config looking correct while never running. */
    $backend->configdRun('cron restart');
}

function cronState(): string
{
    foreach ((new Cron())->jobs->job->iterateItems() as $job) {
        if ($job->origin->isEqual('parentalcontrol')
            && $job->command->isEqual('parentalcontrol sync')) {
            return $job->enabled->isEqual('1') ? 'enabled' : 'disabled';
        }
    }
    return 'absent';
}

/**
 * The block rule is IPv4 only. On a dual-stack firewall a blocked device keeps
 * full IPv6 internet and will prefer it, while every indicator says "blocked".
 * Detect it so the UI can say so rather than lying by omission.
 */
function ipv6Active(): bool
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
 * @param array<string> $days      selected weekday abbreviations, e.g. ['mon','fri']
 * @param int           $nowMin    minutes since local midnight
 * @param ?int          $from      window start in minutes, null if unparseable
 * @param ?int          $to        window end in minutes, null if unparseable
 * @param string        $today     today's weekday abbreviation
 * @param string        $yesterday yesterday's weekday abbreviation
 * @return array{bool, string} [blocked, reason]
 */
function scheduleDecision(array $days, int $nowMin, ?int $from, ?int $to, string $today, string $yesterday): array
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

function timeToMinutes(string $v): ?int
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v, $m)) {
        return null;
    }
    return ((int)$m[1]) * 60 + ((int)$m[2]);
}

/* ---- resolve every device ------------------------------------------------ */

$devices = [];
$blockSet = [];
$msgBlockSet = [];
$msgInUse = false;          /* the messaging objects are made on first use */
foreach ($mdl->devices->iterateItems() as $uuid => $dev) {
    $schedule = deviceSchedule($dev);
    list($blocked, $reason) = resolveDevice($dev, $enabled, $schedule);
    [$mBlocked, $mReason] = messagingDecision(
        $enabled,
        $dev->enabled->isEqual('1'),
        $dev->messaging->getValue(),
        $schedule
    );
    $msgInUse = $msgInUse
        || ($enabled && $dev->enabled->isEqual('1') && !$dev->messaging->isEqual('off'));
    $addr = trim($dev->address->getValue());

    /* a MAC is an identity, not something pf can match - resolve it to whatever
       address the device is using right now */
    if (isMac($addr)) {
        $targets = macToAddresses($addr);
        if (empty($targets) && $blocked) {
            $reason .= ' (MAC not currently resolvable)';
        }
        if (empty($targets) && $mBlocked) {
            $mReason .= ' (MAC not currently resolvable)';
        }
    } else {
        $targets = $addr === '' ? [] : [$addr];
    }
    /* in the form pf keeps, so the table diffs settle and core's state filter takes it */
    $targets = array_map('canonicalTarget', $targets);
    $resolved = implode(', ', $targets);

    $devices[] = [
        'uuid' => $uuid,
        'name' => $dev->name->getValue(),
        'address' => $addr,
        'is_mac' => isMac($addr),
        'resolved' => $resolved,
        'mode' => $dev->mode->getValue(),
        'override' => $dev->override->getValue(),
        'enabled' => $dev->enabled->isEqual('1'),
        'blocked' => $blocked,
        'reason' => $reason,
        'messaging' => $dev->messaging->getValue(),
        'messaging_blocked' => $mBlocked,
        'messaging_reason' => $mReason,
    ];
    if ($blocked) {
        foreach ($targets as $t) {
            $blockSet[$t] = true;
        }
    }
    if ($mBlocked) {
        foreach ($targets as $t) {
            $msgBlockSet[$t] = true;
        }
    }
}

/* ---- status: report only ------------------------------------------------- */

/**
 * Why the messaging objects cannot be used, or null when they can. Their names
 * are fixed, and an alias with one of them that this plugin did not make is
 * never taken over.
 *
 * @param list<array{name: string, description: string}> $aliases every alias
 */
function messagingConflict(array $aliases): ?string
{
    $names = aliasConflicts($aliases, MSG_ALIASES);
    return $names === [] ? null : implode('; ', array_map(
        fn(string $name): string => "alias name $name is used by an alias this plugin does not own",
        $names
    ));
}

/**
 * What is wrong with the messaging objects, or null when nothing is: first the
 * ones missing, then the aliases renamed in the GUI. Core repoints the rules to
 * a renamed alias, while the plugin fills only the table of the fixed name, and
 * that only while its alias has that name. Only reads.
 *
 * @param list<array{name: string, description: string}> $aliases every alias
 * @param list<array{name: string, description: string}> $rules   every rule
 */
function messagingObjectsProblem(array $aliases, array $rules): ?string
{
    $missing = [];
    $renamed = [];
    foreach (MSG_ALIASES as $name => $marker) {
        $alias = findByMarker($aliases, $marker);
        if ($alias === null) {
            $missing[] = $marker;
        } elseif ($alias['name'] !== $name) {
            $renamed[] = "messaging alias renamed: {$alias['name']} should be $name";
        }
    }
    foreach (array_keys(MSG_RULES) as $marker) {
        if (findByMarker($rules, $marker) === null) {
            $missing[] = $marker;
        }
    }

    $problems = [];
    if ($missing !== []) {
        $problems[] = 'messaging objects missing: ' . implode(', ', $missing);
    }
    if ($renamed !== []) {
        $problems[] = implode(', ', $renamed);
    }
    return $problems === [] ? null : implode('; ', $problems);
}

if ($mode === 'status') {
    $current = tableContents($backend, $aliasName);
    $aliases = configRows('/opnsense/OPNsense/Firewall/Alias/aliases/alias');
    $msgConflict = messagingConflict($aliases);
    /* While in use the objects must exist under their fixed names, and the data
       must be readable, or a device shown as denied messaging has nothing
       enforcing it. Out of use their absence is normal. A conflict says more,
       so it wins. */
    $msgError = $msgConflict;
    if ($msgError === null && $msgInUse) {
        $msgError = messagingObjectsProblem($aliases, configRows('/opnsense/OPNsense/Firewall/Filter/rules/rule'))
            ?? (messagingData(MSG_DATA_FILE) === null ? MSG_DATA_MISSING : null);
    }
    $cache = readCache(MSG_CACHE_FILE);
    echo json_encode([
        'enabled' => $enabled,
        'cron' => cronState(),
        'ipv6_active' => ipv6Active(),
        'alias' => $aliasName,
        'in_alias' => count($current),
        'alias_entries' => $current,
        'devices' => $devices,
        'messaging' => [
            'error' => $msgError,
            'devices_table' => MSG_DEVICES_ALIAS,
            'servers_table' => MSG_SERVERS_ALIAS,
            /* under a conflict that table belongs to someone else's alias */
            'entries' => $msgConflict === null ? count(tableContents($backend, MSG_SERVERS_ALIAS)) : 0,
            'learned' => count($cache['learned']),
            /* nothing is refreshed while out of use, so the last refresh's time
               and errors would describe an older use */
            'refreshed_at' => $msgInUse ? $cache['refreshed_at'] : null,
            'apps' => $msgApps,
            'errors' => $msgInUse ? $cache['errors'] : [],
            /* refreshed_at is null both out of use and before the first refresh */
            'in_use' => $msgInUse,
        ],
    ]);
    exit(0);
}

/* ---- make sure the cron entry matches the plugin state ------------------- */

ensureCronJob($backend, $enabled);

/* ---- make sure the alias and rule exist ---------------------------------- */

$cfgChanged = false;
$aliasMdl = new Alias();

/* find our own objects by marker, whatever they are currently called */
$blockNode = null;
$localNode = null;
foreach ($aliasMdl->aliases->alias->iterateItems() as $item) {
    $d = $item->description->getValue();
    if (strpos($d, MARK_BLOCK) === 0) {
        $blockNode = $item;
    } elseif (strpos($d, MARK_LOCAL) === 0) {
        $localNode = $item;
    }
}

/* A renamed external alias means a renamed pf table, so the old one keeps its
   entries and nothing references it any more. Remember it and remove it below,
   or every address blocked at the moment of the rename stays blocked. */
$staleTable = null;

if ($blockNode === null) {
    $blockNode = $aliasMdl->aliases->alias->Add();
    $blockNode->name = $aliasName;
    $blockNode->type = 'external';          /* contents managed here, not in config */
    $blockNode->enabled = '1';
    $blockNode->description = MARK_BLOCK;
    $cfgChanged = true;
} elseif (!$blockNode->name->isEqual($aliasName)) {
    $staleTable = $blockNode->name->getValue();
    $blockNode->name = $aliasName;
    $cfgChanged = true;
}

if ($localNode === null) {
    $localNode = $aliasMdl->aliases->alias->Add();
    $localNode->name = $localAlias;
    $localNode->type = 'network';
    $localNode->enabled = '1';
    $localNode->content = implode("\n", LOCAL_NETS);
    $localNode->description = MARK_LOCAL . ' (block destination is NOT this)';
    $cfgChanged = true;
} elseif (!$localNode->name->isEqual($localAlias)) {
    $localNode->name = $localAlias;
    $cfgChanged = true;
}
if ($cfgChanged && !saveModel($aliasMdl, 'alias')) {
    exit(1);
}

/* one floating block rule: source in the alias, destination NOT local.
   No interface is set, so it covers every interface including ones added
   later - nothing about this installation is assumed. */
$ruleDescr = MARK_RULE . ' for ' . $aliasName;
$filterMdl = new Filter();
$ruleNode = null;
foreach ($filterMdl->rules->rule->iterateItems() as $rule) {
    if (strpos($rule->description->getValue(), MARK_RULE) === 0) {
        $ruleNode = $rule;
        break;
    }
}
$ruleChanged = false;
if ($ruleNode !== null) {
    /* follow a rename: repoint the rule at the current alias names rather than
       leaving it enforcing against the old pair */
    if (!$ruleNode->source_net->isEqual($aliasName)) {
        $ruleNode->source_net = $aliasName;
        $ruleChanged = true;
    }
    if (!$ruleNode->destination_net->isEqual($localAlias)) {
        $ruleNode->destination_net = $localAlias;
        $ruleChanged = true;
    }
    if (!$ruleNode->description->isEqual($ruleDescr)) {
        $ruleNode->description = $ruleDescr;
        $ruleChanged = true;
    }
}
if ($ruleNode === null || $ruleChanged) {
    if ($ruleNode === null) {
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
    }
    if (!saveModel($filterMdl, 'rule')) {
        exit(1);
    }
    $cfgChanged = true;
}

/* ---- make sure the messaging aliases and rules exist --------------------- */

/**
 * Create whichever messaging aliases and rules are missing, found by marker.
 *
 * Called while some device uses messaging, so the objects appear on first use;
 * only uninstall removes them. When an alias that is not the plugin's holds one
 * of the fixed names, nothing is created or changed. Errors are returned, never
 * exited on: a messaging problem must not stop the internet block.
 *
 * @param bool $cfgChanged set when anything was saved, so that the caller's
 *                         single filter reload covers it
 * @return ?string null on success, else why messaging cannot be enforced
 */
function ensureMessagingObjects(bool &$cfgChanged): ?string
{
    $aliasMdl = new Alias();
    $aliases = modelRows($aliasMdl->aliases->alias);
    $conflict = messagingConflict($aliases);
    if ($conflict !== null) {
        return $conflict;
    }

    $aliasAdded = false;
    foreach (MSG_ALIASES as $name => $marker) {
        if (findByMarker($aliases, $marker) === null) {
            $node = $aliasMdl->aliases->alias->Add();
            $node->name = $name;
            $node->type = 'external';           /* contents managed here, not in config */
            $node->enabled = '1';
            $node->description = $marker;
            $aliasAdded = true;
        }
    }
    if ($aliasAdded) {
        if (!saveModel($aliasMdl, 'alias')) {
            return 'aliases failed validation';
        }
        $cfgChanged = true;
        /* Core keeps the alias names a rule may use in a static list for the
           whole process (BaseListField's option cache), and the internet rule's
           Filter model above filled it before these two existed. Saving them
           does not refresh it: drop it, or the rules below fail validation. */
        (new NetworkAliasField())->resetStaticOptions();
    }

    $filterMdl = new Filter();
    $rules = modelRows($filterMdl->rules->rule);
    $ruleAdded = false;
    foreach (MSG_RULES as $marker => [$protocol, $destination, $port]) {
        if (findByMarker($rules, $marker) === null) {
            $rule = $filterMdl->rules->rule->Add();
            $rule->enabled = '1';
            $rule->action = 'block';
            $rule->quick = '1';
            $rule->direction = 'in';
            $rule->ipprotocol = 'inet';
            $rule->protocol = $protocol;
            $rule->source_net = MSG_DEVICES_ALIAS;
            $rule->destination_net = $destination;
            $rule->destination_port = $port;
            $rule->description = $marker;
            $ruleAdded = true;
        }
    }
    if ($ruleAdded) {
        if (!saveModel($filterMdl, 'rule')) {
            return 'rules failed validation';
        }
        $cfgChanged = true;
    }
    return null;
}

/* The objects are fine unless an alias that is not ours holds one of their
   names. That is checked every run, in use or not, because the device table is
   synced every run (below); only creating the objects waits for first use. */
$msgError = messagingConflict(modelRows($aliasMdl->aliases->alias));
$msgObjectsOk = $msgError === null;
if ($msgObjectsOk && $msgInUse) {
    /* an exception too, say a failed config save, must not stop the internet
       block below */
    try {
        $msgError = ensureMessagingObjects($cfgChanged);
    } catch (\Throwable $e) {
        $msgError = $e->getMessage();
    }
}
if ($msgError !== null) {
    fwrite(STDERR, "messaging: $msgError\n");
}

/* Entries go into a fixed-name table only while the plugin's own alias has that
   name. After a rename by hand, or when the alias could not be made, `pfctl -T add`
   would create the table itself, kept by pf, and uninstall, which finds the tables
   by marker, would never remove it. Deletions still run. */
$aliasRows = configRows('/opnsense/OPNsense/Firewall/Alias/aliases/alias');
$addTo = [];
foreach (MSG_ALIASES as $name => $marker) {
    $own = findByMarker($aliasRows, $marker);
    $addTo[$name] = $own !== null && $own['name'] === $name;
}

if ($cfgChanged) {
    $backend->configdRun('filter reload');
}

/* The renamed-away table is no longer referenced by any rule once the reload
   above has run, but pf keeps it, entries and all, as it keeps every alias table.
   Kill it, which empties and removes it, so a device blocked at the moment of the
   rename is not left blocked by a table nobody maintains. */
if ($staleTable !== null) {
    $backend->configdpRun('filter kill table', [$staleTable]);
    fwrite(STDERR, "alias renamed $staleTable -> $aliasName; removed the old table\n");
}

/* ---- sync the table ------------------------------------------------------ */

$want = array_keys($blockSet);
$diff = syncTable($backend, $aliasName, $want);
foreach ($diff['errors'] as $error) {
    fwrite(STDERR, "$error\n");
}

/* Established connections survive a new block, so a stream already running
   keeps going until it ends by itself. Dropping their states is what makes
   "off" mean off. Best effort: never fail the sync over it. */
if ($mdl->general->kill_states->isEqual('1')) {
    foreach ($diff['added'] as $addr) {
        /* pfctl -k takes a host. For a CIDR, explode()[0] is the network
           address - killing states for an address nobody holds. Skip those and
           say so rather than appearing to have done something. */
        if (strpos($addr, '/') !== false) {
            fwrite(STDERR, "not dropping states for network $addr (pfctl -k takes a host)\n");
            continue;
        }
        @exec('/sbin/pfctl -k ' . escapeshellarg($addr) . ' >/dev/null 2>&1');
    }
}

printf("blocked=%d added=%d removed=%d\n", count($want), count($diff['added']), count($diff['removed']));

/* ---- refresh the messaging server list ----------------------------------- */

/* After the internet switch, so a slow resolver never delays it, and before the
   device table, so a device newly denied messaging meets this run's list. Only
   while in use: until then the list has no effect, so nothing is looked up or
   downloaded and the servers table is left as it is. */
$dest = [];             /* this run's servers, for dropping connections below */
$addedDest = [];        /* the servers this run added to their table */
$cache = null;          /* the cache this run wrote; null when it did not refresh */
if ($msgObjectsOk && $msgInUse) {
    $data = messagingData(MSG_DATA_FILE);
    if ($data === null) {
        fwrite(STDERR, 'messaging: ' . MSG_DATA_MISSING . "\n");
    } else {
        $cache = readCache(MSG_CACHE_FILE);
        /* an exception must not stop the device table below */
        try {
            [$dest, $addedDest, $cache] = refreshServerList(
                $backend,
                MSG_SERVERS_ALIAS,
                $data,
                $msgApps,
                $cache,
                $addTo[MSG_SERVERS_ALIAS]
            );
        } catch (\Throwable $e) {
            $cache['errors'] = ['refresh failed: ' . $e->getMessage()];
        }
        foreach ($cache['errors'] as $error) {
            fwrite(STDERR, "messaging: $error\n");
        }
        writeCache(MSG_CACHE_FILE, $cache);
    }
}

/* ---- sync the messaging device table ------------------------------------- */

/* Every run, in use or not: a device switched back to "Not blocked", or the
   plugin disabled, must leave the table even when nobody else uses messaging.
   Never under a conflict: that table belongs to someone else's alias. */
if ($msgObjectsOk) {
    $msgWant = array_keys($msgBlockSet);
    $msgDiff = syncTable($backend, MSG_DEVICES_ALIAS, $msgWant, $addTo[MSG_DEVICES_ALIAS]);
    printf(
        "messaging blocked=%d added=%d removed=%d\n",
        count($msgWant),
        count($msgDiff['added']),
        count($msgDiff['removed'])
    );
    foreach ($msgDiff['errors'] as $error) {
        fwrite(STDERR, "messaging: $error\n");
    }

    /* As for the internet switch, a new block must also cut what is already open. */
    $failures = $msgDiff['errors'];
    if ($mdl->general->kill_states->isEqual('1')) {
        $failures = array_merge($failures, dropMessagingStates($backend, $msgWant, $msgDiff['added'], $dest, $addedDest));
    }
    /* status shows the errors the cache holds */
    if ($failures !== [] && $cache !== null) {
        $cache['errors'] = array_merge($cache['errors'], $failures);
        writeCache(MSG_CACHE_FILE, $cache);
    }
}
