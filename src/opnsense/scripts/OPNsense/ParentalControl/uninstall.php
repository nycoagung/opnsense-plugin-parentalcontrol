#!/usr/local/bin/php
<?php

/**
 * Remove everything this plugin added to the firewall's configuration.
 *
 * Config only - files are the shell wrapper's job. Splitting it that way is
 * deliberate: this runs as a configd action, and a script that deleted its own
 * action file and then restarted configd would be killed partway through.
 *
 * Nothing is changed while anything else in the configuration refers to one of
 * the plugin's aliases: deleting the alias would leave that rule, or whatever it
 * is, pointing at nothing. The places are listed and the exit status is 1.
 *
 * Order matters. The pf tables are flushed and the rules deleted before the
 * aliases go, so nothing is left blocked by an object that no longer has an
 * owner, and the tables themselves are removed once the reload has dropped the
 * rules that used them. Everything is matched by description marker, so a
 * renamed alias is still found.
 *
 * With --final, as uninstall.sh runs it before removing the files, a run that
 * removes everything also leaves UNINSTALL_MARKER behind: until the next install,
 * every sync then changes nothing. Without it, as the configd action runs it, only
 * the configuration side goes, and the next sync puts it all back.
 *
 * Idempotent: safe to run when some or all of it is already gone.
 */

require_once("config.inc");
require_once("util.inc");

use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Cron\Cron;
use OPNsense\Firewall\Alias;
use OPNsense\Firewall\Filter;

$args = isset($argv) ? array_slice($argv, 1) : [];
if ($args !== [] && $args !== ['--final']) {
    fwrite(STDERR, "usage: uninstall.php [--final]\n");
    exit(1);
}
$final = $args === ['--final'];

foreach (['markers.php', 'messaging.php'] as $lib) {
    /* an older installer's file list leaves out the files added since */
    if (!is_file(__DIR__ . "/lib/$lib")) {
        fwrite(STDERR, "plugin files incomplete (missing lib/$lib): run 'configctl parentalcontrol install' again\n");
        exit(1);
    }
    require_once(__DIR__ . "/lib/$lib");
}

/* Hold the sync lock, as sync.php does, and read the configuration again once it
   is held: a sync that is mid-run would otherwise save its own copy after this
   one and put back what this removes. */
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0700);         /* a sync starting at the same moment may make it first */
}
/* With --final the marker goes down before the lock is taken, so a sync that gets
   the lock next changes nothing. Any exit before everything is removed takes it
   away again: a refusal changed nothing, and a run that failed part way must not
   stop the syncs that would put the rest back. */
if ($final) {
    /* A signal kills PHP without running shutdown functions; turn it into exit(1),
       which runs them. Without pcntl a signal still leaves the marker behind. */
    if (function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, function (): void {
                /* a second signal would otherwise end the clean-up below before it is done */
                pcntl_async_signals(false);
                exit(1);
            });
        }
    }
    /* registered first, so no moment exists with the marker made and nothing to remove it */
    $cleanUp = [UNINSTALL_MARKER];  /* removed at exit, unless the run gets to the end */
    register_shutdown_function(function () use (&$cleanUp): void {
        foreach ($cleanUp as $file) {
            @unlink($file);
        }
    });
    if (!@touch(UNINSTALL_MARKER)) {
        fwrite(STDERR, "not removing anything: cannot create " . UNINSTALL_MARKER . "\n");
        exit(1);
    }
}
$lock = @fopen(DATA_DIR . '/sync.lock', 'c');
if ($lock === false) {
    fwrite(STDERR, "not removing anything: cannot open " . DATA_DIR . "/sync.lock\n");
    exit(1);
}
for ($waited = 0; !flock($lock, LOCK_EX | LOCK_NB); $waited++) {
    if ($waited === 0) {
        echo "waiting for a running sync to finish\n";
    }
    if ($waited >= 30) {
        fwrite(STDERR, "not removing anything: a sync is still running\n");
        exit(1);
    }
    sleep(1);
}
Config::getInstance()->forceReload();

/* 1. the plugin's own aliases and rules, found by marker whatever they are called
      now, and its cron job, found by origin */
$aliasMdl = new Alias();
$aliasUuids = [];           /* uuid => name */
$tables = [];               /* the external aliases' names: pf holds their addresses */
foreach ($aliasMdl->aliases->alias->iterateItems() as $uuid => $item) {
    foreach ([MARK_BLOCK, MARK_LOCAL, MARK_MSG_DEVICES, MARK_MSG_SERVERS] as $marker) {
        if (strpos($item->description->getValue(), $marker) === 0) {
            $aliasUuids[$uuid] = $item->name->getValue();
            if ($marker !== MARK_LOCAL) {
                $tables[] = $item->name->getValue();
            }
            break;
        }
    }
}
$filterMdl = new Filter();
$ruleUuids = [];
foreach ($filterMdl->rules->rule->iterateItems() as $uuid => $rule) {
    foreach ([MARK_RULE, MARK_MSG_RULE, MARK_NOTIFY_RULE] as $marker) {
        if (strpos($rule->description->getValue(), $marker) === 0) {
            $ruleUuids[] = $uuid;
            break;
        }
    }
}
$cron = new Cron();
$jobUuids = [];
foreach ($cron->jobs->job->iterateItems() as $uuid => $job) {
    if ($job->origin->isEqual('parentalcontrol')) {
        $jobUuids[] = $uuid;
    }
}

/* 2. before any change: refuse while anything else refers to one of the aliases */
$xml = @file_get_contents('/conf/config.xml');
if ($xml === false) {
    fwrite(STDERR, "not removing anything: could not read /conf/config.xml\n");
    exit(1);
}
$refs = findForeignReferences($xml, array_merge(array_keys($aliasUuids), $ruleUuids, $jobUuids),
    array_values($aliasUuids));
if ($refs === [CONFIG_UNPARSED]) {
    fwrite(STDERR, 'not removing anything: ' . CONFIG_UNPARSED . "\n");
    exit(1);
}
if ($refs !== []) {
    fwrite(STDERR, "not removing anything: these refer to the plugin's aliases:\n" . implode("\n", $refs) . "\n");
    exit(1);
}

$backend = new Backend();
/* configd answers '' when it is not running: nothing below would reach pf, and the
   rules would stay loaded with their aliases gone */
if ($backend->configdRun('filter list tables') === '') {
    fwrite(STDERR, "not removing anything: configd is not answering\n");
    exit(1);
}
$removed = [];

/* 3. flush the pf tables first - after an alias is gone its table cannot be
      addressed, and anything still in it would keep matching until the reload lands */
foreach ($tables as $name) {
    $backend->configdpRun('filter delete table', [$name, 'ALL']);
    $removed[] = "flushed table $name";
}

/* 4. the rules, before the aliases they reference */
foreach ($ruleUuids as $uuid) {
    $filterMdl->rules->rule->del($uuid);
    $removed[] = 'rule ' . substr($uuid, 0, 8);
}
if (!empty($ruleUuids)) {
    $filterMdl->serializeToConfig();
}

/* 5. the aliases */
foreach ($aliasUuids as $uuid => $name) {
    $aliasMdl->aliases->alias->del($uuid);
    $removed[] = "alias $name";
}
if (!empty($aliasUuids)) {
    $aliasMdl->serializeToConfig();
}

/* 6. our cron job */
foreach ($jobUuids as $uuid) {
    $cron->jobs->job->del($uuid);
    $removed[] = 'cron job ' . substr($uuid, 0, 8);
}
if (!empty($jobUuids)) {
    $cron->serializeToConfig();
}

if (!empty($ruleUuids) || !empty($aliasUuids) || !empty($jobUuids)) {
    Config::getInstance()->save();
}
if (!empty($ruleUuids) || !empty($aliasUuids)) {
    /* configd answers "OK" and two newlines when the reload ran */
    if (trim($backend->configdRun('filter reload')) !== 'OK') {
        echo "warning: the filter reload failed, so pf may still hold the plugin's rules and tables "
            . "until the next filter reload: run 'configctl filter reload'\n";
    }
}
/* 7. the emptied tables themselves. Core declares every alias table "persist", and
      its own clean-up skips external aliases, so pf keeps them until they are
      killed - only now, when no loaded rule uses them. A table pf does not list
      answers an error, which is no failure here. */
foreach ($tables as $name) {
    if (trim($backend->configdpRun('filter kill table', [$name])) === 'OK') {
        $removed[] = "removed table $name";
    }
}
if (!empty($jobUuids)) {
    $backend->configdRun('cron restart');
}
$cleanUp = [];                  /* everything is removed: a --final run's marker stays */

echo empty($removed) ? "nothing to remove\n" : implode("\n", $removed) . "\n";
