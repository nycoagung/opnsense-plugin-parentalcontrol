<?php

namespace OPNsense\ParentalControl\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\ParentalControl\ParentalControl;

/**
 * 1.4.0 keeps the game servers in the settings, as a list the parent edits. It starts
 * as the games gaming.json ships, all switched on, so an upgrade keeps blocking what
 * the release before blocked, and a new install starts the same way. From then on a
 * release only adds the games it newly ships, at the next sync (addShippedGames()),
 * which also fills the list should this migration not have run.
 */
class M1_4_0 extends BaseModelMigration
{
    /* beside the scripts: the same path in the repository and on the firewall */
    private const GAMING_JSON = __DIR__ . '/../../../../../../scripts/OPNsense/ParentalControl/gaming.json';

    /**
     * @param mixed $model the model; core's BaseModelMigration::run() declares no type,
     *        and an override may not narrow it
     */
    public function run($model): void
    {
        parent::run($model);
        if ($model instanceof ParentalControl) {
            $model->addShippedGames(shippedServers(self::GAMING_JSON));
        }
    }
}
