#!/usr/local/bin/php
<?php

/**
 * Remove everything this plugin added to the firewall's configuration.
 *
 * Config only - files are the shell wrapper's job. Splitting it that way is
 * deliberate: this runs as a configd action, and a script that deleted its own
 * action file and then restarted configd would be killed partway through.
 *
 * Order matters. The pf table is flushed and the rule deleted before the
 * aliases go, so nothing is left blocked by an object that no longer has an
 * owner. Everything is matched by description marker, so a renamed alias is
 * still found.
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

const MARK_BLOCK = 'Parental Control: devices currently denied internet';
const MARK_LOCAL = 'Parental Control: local networks';
const MARK_RULE  = 'Parental Control: block internet';

$backend = new Backend();
$removed = [];

/* 1. flush the pf table first - after the alias is gone it cannot be addressed,
      and anything still in it would keep matching until the reload lands */
$aliasMdl = new Alias();
$blockName = null;
foreach ($aliasMdl->aliases->alias->iterateItems() as $item) {
    if (strpos((string)$item->description, MARK_BLOCK) === 0) {
        $blockName = (string)$item->name;
    }
}
if ($blockName !== null) {
    $backend->configdpRun('filter delete table', [$blockName, 'ALL']);
    $removed[] = "flushed table $blockName";
}

/* 2. the rule, before the aliases it references */
$filterMdl = new Filter();
$ruleUuids = [];
foreach ($filterMdl->rules->rule->iterateItems() as $uuid => $rule) {
    if (strpos((string)$rule->description, MARK_RULE) === 0) {
        $ruleUuids[] = $uuid;
    }
}
foreach ($ruleUuids as $uuid) {
    $filterMdl->rules->rule->del($uuid);
    $removed[] = 'rule ' . substr($uuid, 0, 8);
}
if (!empty($ruleUuids)) {
    $filterMdl->serializeToConfig();
}

/* 3. both aliases */
$aliasUuids = [];
foreach ($aliasMdl->aliases->alias->iterateItems() as $uuid => $item) {
    $d = (string)$item->description;
    if (strpos($d, MARK_BLOCK) === 0 || strpos($d, MARK_LOCAL) === 0) {
        $aliasUuids[$uuid] = (string)$item->name;
    }
}
foreach ($aliasUuids as $uuid => $name) {
    $aliasMdl->aliases->alias->del($uuid);
    $removed[] = "alias $name";
}
if (!empty($aliasUuids)) {
    $aliasMdl->serializeToConfig();
}

/* 4. our cron job */
$cron = new Cron();
$jobUuids = [];
foreach ($cron->jobs->job->iterateItems() as $uuid => $job) {
    if ((string)$job->origin === 'parentalcontrol') {
        $jobUuids[] = $uuid;
    }
}
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
    $backend->configdRun('filter reload');
}
if (!empty($jobUuids)) {
    $backend->configdRun('cron restart');
}

echo empty($removed) ? "nothing to remove\n" : implode("\n", $removed) . "\n";
