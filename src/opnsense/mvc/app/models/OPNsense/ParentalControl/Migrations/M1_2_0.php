<?php

namespace OPNsense\ParentalControl\Migrations;

use OPNsense\Base\BaseModelMigration;

/**
 * 1.2.0 adds Facebook & Instagram, KakaoTalk and Viber to the apps a device denied
 * messaging is kept from. A new install selects every app by default; a saved
 * selection gains the new ones here, so an upgrade does the same. An explicitly
 * empty selection stays empty.
 */
class M1_2_0 extends BaseModelMigration
{
    private const NEW_APPS = ['facebook_instagram', 'kakaotalk', 'viber'];

    /**
     * The saved selection with the new apps added, sorted, without blanks or
     * repeats; an empty selection stays empty.
     *
     * @param string        $stored the comma-separated app ids saved in the config
     * @param array<string> $newIds the app ids to add
     */
    public static function withNewApps(string $stored, array $newIds): string
    {
        $apps = array_filter(explode(',', $stored), fn(string $id): bool => $id !== '');
        if ($apps === []) {
            return '';
        }
        $apps = array_unique(array_merge($apps, $newIds));
        sort($apps);
        return implode(',', $apps);
    }

    /* $model stays untyped: core's BaseModelMigration::run() declares no type, and an
       override may not narrow it */
    public function run($model): void
    {
        parent::run($model);
        $field = $model->general->messaging_apps;
        $field->setValue(self::withNewApps($field->getValue(), self::NEW_APPS));
    }
}
