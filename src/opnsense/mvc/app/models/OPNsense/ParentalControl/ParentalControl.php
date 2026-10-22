<?php

namespace OPNsense\ParentalControl;

use OPNsense\Base\BaseModel;
use OPNsense\Base\FieldTypes\ArrayField;
use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\Messages\Message;
use OPNsense\Base\Validation;

/* The game list's rules live with the scripts, which apply them every run, so a save
   and a sync can never disagree. The path is the same in the repository and under
   /usr/local/opnsense. */
require_once __DIR__ . '/../../../../../scripts/OPNsense/ParentalControl/lib/markers.php';
require_once __DIR__ . '/../../../../../scripts/OPNsense/ParentalControl/lib/messaging.php';
require_once __DIR__ . '/../../../../../scripts/OPNsense/ParentalControl/lib/gaming.php';
require_once __DIR__ . '/../../../../../scripts/OPNsense/ParentalControl/lib/games.php';

/**
 * Per-device internet access control, and the game list the gaming block uses.
 *
 * The model holds intent only. Enforcement lives in scripts/sync.php, which
 * resolves each device to allowed/blocked for the current moment and syncs the
 * externally managed alias.
 *
 * The game list is the parent's. It starts as the games gaming.json ships, and a
 * later release only adds the games it newly ships (addShippedGames()). A game is
 * saved only when it passes gameProblems(), the checks that keep any game from
 * blocking a service that other sites share.
 */
class ParentalControl extends BaseModel
{
    /* gaming.json and messaging.json, beside the scripts */
    private const SCRIPTS = __DIR__ . '/../../../../../scripts/OPNsense/ParentalControl';

    /**
     * The list's games, by uuid, as gameEntry() reads them.
     *
     * @return array<string, array{name: string, enabled: bool, hosts: list<string>, proxy_hosts: list<string>, ranges: list<string>}>
     */
    public function gameEntries(): array
    {
        $entries = [];
        foreach ($this->node('games')?->iterateItems() ?? [] as $uuid => $item) {
            if ($item instanceof BaseField) {
                $fields = [];
                foreach (['enabled', 'name', 'hosts', 'proxy_hosts', 'ranges'] as $name) {
                    $fields[$name] = self::text($item->$name);
                }
                $entries[self::text($uuid)] = gameEntry($fields);
            }
        }
        return $entries;
    }

    /**
     * Add the games gaming.json newly ships, as gamesToAdd() decides: a key the list
     * has never held joins, switched on, unless the list already has a game of its
     * name or one of its hosts. Every new key is held from then on, joined or not, so
     * a game the parent deleted never comes back. Nothing is saved here: the 1.4.0
     * migration and sync.php save.
     *
     * @param ?array<mixed> $servers gaming.json's servers (shippedServers()); null,
     *        for a file that cannot be read, changes nothing
     * @return array{added: list<string>, clashes: list<string>, changed: bool} the keys
     *         added, a line for each new game left out, and whether there is anything to
     *         save: a game added or a new key held
     */
    public function addShippedGames(?array $servers): array
    {
        $games = $this->node('games');
        $held = $this->node('general.games_seen');
        if ($servers === null || !$games instanceof ArrayField || $held === null) {
            return ['added' => [], 'clashes' => [], 'changed' => false];
        }
        $before = $held->getValue();
        $offer = gamesToAdd($servers, $before === '' ? [] : explode(',', $before), $this->gameEntries());
        foreach ($offer['add'] as $game) {
            $games->add()->setNodes([
                'enabled' => $game['enabled'] ? '1' : '0',
                'name' => $game['name'],
                'hosts' => implode(',', $game['hosts']),
                'proxy_hosts' => implode(',', $game['proxy_hosts']),
                'ranges' => implode(',', $game['ranges']),
            ]);
        }
        /* every game added brings a new key, so the held keys tell whether anything changed */
        $changed = implode(',', $offer['seen']) !== $before;
        if ($changed) {
            $held->setValue(implode(',', $offer['seen']));
        }
        return ['added' => array_map('strval', array_keys($offer['add'])), 'clashes' => $offer['clashes'],
            'changed' => $changed];
    }

    /**
     * Core's checks, and each game's (gameProblems()): against the other games,
     * gaming.json's shared hosting domains, and the ranges no game may block, which
     * are the shared front ends and CDNs and the networks never treated as gaming.
     *
     * As core does for its own fields, a game is checked when it changed, or when the
     * whole model is, so a game saved before a data update never blocks another save.
     * Each message names its field, games.<uuid>.<field>, so the dialog shows it
     * there. A data file that cannot be read leaves out what it holds; the run-time
     * guards still apply.
     *
     * @param bool $validateFullModel
     * @return Validation
     */
    public function performValidation($validateFullModel = false)
    {
        /** @var Validation $messages core's docblock names a class that does not exist */
        $messages = parent::performValidation($validateFullModel);
        $checked = [];
        foreach ($this->node('games')?->iterateItems() ?? [] as $uuid => $item) {
            if ($item instanceof BaseField && ($validateFullModel || $item->isFieldChanged())) {
                $checked[] = self::text($uuid);
            }
        }
        if ($checked === []) {
            return $messages;
        }
        $gaming = jsonSchema1(self::SCRIPTS . '/gaming.json') ?? [];
        $messaging = jsonSchema1(self::SCRIPTS . '/messaging.json');
        $domains = array_values(array_filter((array)($gaming['shared_hosting_domains'] ?? []), 'is_string'));
        $guarded = array_merge(guardedRanges($gaming, $messaging), neverBlocked([], $gaming, $messaging));
        $entries = $this->gameEntries();
        foreach ($checked as $uuid) {
            $others = array_diff_key($entries, [$uuid => true]);
            foreach (gameProblems($entries[$uuid], $others, $domains, $guarded) as $field => $problem) {
                $messages->appendMessage(new Message($problem, "games.$uuid.$field"));
            }
        }
        return $messages;
    }

    /* a node of this model by reference, such as general.games_seen, or null */
    private function node(string $reference): ?BaseField
    {
        /** @var ?BaseField $node core's docblock names OPNsense\Base\BaseField, which does not exist */
        $node = $this->getNodeByReference($reference);
        return $node;
    }

    /* a node's text: a field prints its value, and a key is a string already */
    private static function text(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string)$value : '';
    }
}
