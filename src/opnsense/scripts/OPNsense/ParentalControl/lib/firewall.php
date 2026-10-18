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
 *
 * @param list<string> $want
 * @return array{added: list<string>, removed: list<string>}
 */
function syncTable(Backend $backend, string $table, array $want): array
{
    $diff = tableDiff($want, tableContents($backend, $table));
    foreach ($diff['added'] as $addr) {
        $backend->configdpRun('filter add table', [$table, $addr]);
    }
    foreach ($diff['removed'] as $addr) {
        $backend->configdpRun('filter delete table', [$table, $addr]);
    }
    return $diff;
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
