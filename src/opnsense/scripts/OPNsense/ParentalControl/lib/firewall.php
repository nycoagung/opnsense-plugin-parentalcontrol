<?php

/**
 * The firewall's objects and pf tables: saving a model, reading a table and
 * making it hold a given set, the name and description rows of the aliases and
 * rules, and finding the plugin's own objects among them by marker.
 *
 * These use OPNsense classes and configd, so they run only on the firewall.
 * sync.php loads this file after lib/messaging.php: syncTable() calls its
 * tableDiff().
 */

use OPNsense\Base\BaseModel;
use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;

/**
 * Validate and save a model. On failure print every message, prefixed with
 * $what - a bare "validation failed" makes a first install impossible to
 * diagnose without a shell - and save nothing.
 */
function saveModel(BaseModel $mdl, string $what): bool
{
    $val = $mdl->performValidation();
    if ($val->count() > 0) {
        foreach ($val->getMessages() as $msg) {
            fwrite(STDERR, "$what: " . $msg->getField() . ": " . $msg->getMessage() . "\n");
        }
        return false;
    }
    $mdl->serializeToConfig();
    Config::getInstance()->save();
    return true;
}

/**
 * Current contents of the pf table.
 *
 * 'filter list table' answers {"items": [...]} - the payload is under 'items',
 * not at the top level, which is how core's own alias utility reads it. Getting
 * that wrong returns an empty list silently, which makes every address look
 * absent: nothing is ever removed from the table, so a device stays blocked
 * after its schedule reopens.
 *
 * @return list<string>
 */
function tableContents(Backend $backend, string $alias): array
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
            if (isset($row['ip']) && is_string($row['ip'])) {
                $out[] = $row['ip'];
            }
        } elseif (is_string($row) && $row !== '') {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Make a pf table hold exactly $want, adding and deleting only the differences.
 * With $add false it only deletes, and reports nothing added: `pfctl -T add` makes a
 * table that does not exist, and such a table stays until it is killed.
 *
 * All additions go to pfctl in one call and all deletions in another
 * (pfTableBulk()): configd's table actions take one address each, and the servers
 * table can gain thousands at once. When pfctl cannot run, each address goes
 * through configd instead.
 *
 * @param list<string> $want
 * @param ?callable(string, string, list<string>): bool $pfctl pfTableBulk() unless given
 * @return array{added: list<string>, removed: list<string>}
 */
function syncTable(Backend $backend, string $table, array $want, bool $add = true, ?callable $pfctl = null): array
{
    $diff = tableDiff($want, tableContents($backend, $table));
    if (!$add) {
        $diff['added'] = [];
    }
    $pfctl ??= pfTableBulk(...);
    foreach (['add' => $diff['added'], 'delete' => $diff['removed']] as $op => $addrs) {
        if ($addrs === [] || $pfctl($table, $op, $addrs)) {
            continue;
        }
        foreach ($addrs as $addr) {
            $backend->configdpRun("filter $op table", [$table, $addr]);
        }
    }
    return $diff;
}

/**
 * `pfctl -t $table -T $op` ('add' or 'delete') for every address at once, read from
 * stdin; false when pfctl did not run or failed.
 *
 * @param list<string> $addrs
 */
function pfTableBulk(string $table, string $op, array $addrs): bool
{
    $proc = @proc_open(['/sbin/pfctl', '-t', $table, '-T', $op, '-f', '/dev/stdin'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    fwrite($pipes[0], implode("\n", $addrs) . "\n");
    fclose($pipes[0]);
    return proc_close($proc) === 0;
}

/**
 * Name and description of each item of a model's array field ('' for a field
 * the item does not have: rules have no name). For aliases this includes the
 * ones core and plugins generate, which config.xml does not hold.
 *
 * @return list<array{name: string, description: string}>
 */
function modelRows(BaseField $items): array
{
    $rows = [];
    foreach ($items->iterateItems() as $item) {
        $rows[] = [
            'name' => isset($item->name) ? $item->name->getValue() : '',
            'description' => isset($item->description) ? $item->description->getValue() : '',
        ];
    }
    return $rows;
}

/**
 * The same rows read from config.xml as loaded, for the elements at $xpath.
 * No model is built: building the Filter model runs configd calls and can
 * rewrite core's alias cache, which a status poll must not do.
 *
 * @return list<array{name: string, description: string}>
 */
function configRows(string $xpath): array
{
    $rows = [];
    /* SimpleXML nodes, not model fields, so a string cast is how their text is read */
    foreach (Config::getInstance()->object()->xpath($xpath) ?: [] as $item) {
        $rows[] = ['name' => (string)$item->name, 'description' => (string)$item->description];
    }
    return $rows;
}

/**
 * The first of $rows whose description starts with $marker, or null: how the
 * plugin finds its own objects, whatever they are called now.
 *
 * @param list<array{name: string, description: string}> $rows
 * @return ?array{name: string, description: string}
 */
function findByMarker(array $rows, string $marker): ?array
{
    foreach ($rows as $row) {
        if (strpos($row['description'], $marker) === 0) {
            return $row;
        }
    }
    return null;
}
