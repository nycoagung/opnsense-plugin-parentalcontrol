#!/usr/local/bin/php
<?php
/**
 * The settings migration to 1.2.0: a saved app selection gains the apps added in
 * 1.2.0, and an explicitly empty selection stays empty.
 *
 * Needs plain PHP; no OPNsense: core's migration base class is stood in for below,
 * and run() gets a stand-in model with the one field it touches.
 *
 *   php tests/MigrationTest.php
 */

namespace OPNsense\Base {
    class BaseModelMigration
    {
        public function run($model)
        {
        }
    }
}

namespace {
    require __DIR__ . '/../src/opnsense/mvc/app/models/OPNsense/ParentalControl/Migrations/M1_2_0.php';

    use OPNsense\ParentalControl\Migrations\M1_2_0;

    $pass = $fail = 0;

    function check(string $name, mixed $got, mixed $want): void
    {
        global $pass, $fail;
        if (json_encode($got) === json_encode($want)) {
            $pass++;
            echo "  pass  $name\n";
        } else {
            $fail++;
            echo "  FAIL  $name  got " . json_encode($got) . " want " . json_encode($want) . "\n";
        }
    }

    function finish(): never
    {
        global $pass, $fail;
        printf("\n%d passed, %d failed\n", $pass, $fail);
        exit($fail === 0 ? 0 : 1);
    }

    $new = ['facebook_instagram', 'kakaotalk', 'viber'];
    check('migration: a saved selection gains the new apps',
        M1_2_0::withNewApps('line,signal,snapchat,telegram,whatsapp', $new),
        'facebook_instagram,kakaotalk,line,signal,snapchat,telegram,viber,whatsapp');
    check('migration: an empty selection stays empty',
        M1_2_0::withNewApps('', $new),
        '');
    check('migration: apps already present are not repeated',
        M1_2_0::withNewApps('viber,whatsapp', ['viber', 'kakaotalk']),
        'kakaotalk,viber,whatsapp');
    check('migration: blank items are ignored',
        M1_2_0::withNewApps('line,,whatsapp', ['viber']),
        'line,viber,whatsapp');

    /* run() reads and writes general.messaging_apps through the field's own methods */
    $field = fn(string $value): object => new class ($value) {
        public function __construct(public string $value)
        {
        }
        public function getValue(): string
        {
            return $this->value;
        }
        public function setValue(string $value): void
        {
            $this->value = $value;
        }
    };
    $saved = $field('line,signal,snapchat,telegram,whatsapp');
    (new M1_2_0())->run((object)['general' => (object)['messaging_apps' => $saved]]);
    check('migration: run() adds the new apps to a saved selection',
        $saved->value,
        'facebook_instagram,kakaotalk,line,signal,snapchat,telegram,viber,whatsapp');
    $empty = $field('');
    (new M1_2_0())->run((object)['general' => (object)['messaging_apps' => $empty]]);
    check('migration: run() leaves an empty selection empty',
        $empty->value,
        '');

    finish();
}
