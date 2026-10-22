#!/usr/local/bin/php
<?php
/**
 * The settings migrations. To 1.2.0: a saved app selection gains the apps added in
 * 1.2.0, and an explicitly empty selection stays empty. To 1.4.0: the game list is
 * offered every game gaming.json ships, so an upgrade keeps blocking what it blocked.
 *
 * Needs plain PHP; no OPNsense: core's migration base class and the model are stood
 * in for below, with the one field and the one method the migrations touch.
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

namespace OPNsense\ParentalControl {
    /* the model, standing in: it records what the 1.4.0 migration offers the list */
    class ParentalControl
    {
        /** @var ?array<mixed> */
        public ?array $offered = null;

        /**
         * @param ?array<mixed> $servers
         * @return array{added: list<string>, clashes: list<string>, changed: bool}
         */
        public function addShippedGames(?array $servers): array
        {
            $this->offered = $servers;
            return ['added' => [], 'clashes' => [], 'changed' => false];
        }
    }
}

namespace {
    require __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/lib/games.php';
    require __DIR__ . '/../src/opnsense/mvc/app/models/OPNsense/ParentalControl/Migrations/M1_2_0.php';
    require __DIR__ . '/../src/opnsense/mvc/app/models/OPNsense/ParentalControl/Migrations/M1_4_0.php';

    use OPNsense\ParentalControl\Migrations\M1_2_0;
    use OPNsense\ParentalControl\Migrations\M1_4_0;
    use OPNsense\ParentalControl\ParentalControl;

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

    /* 1.4.0 reads gaming.json beside the scripts, by a path relative to itself that is
       the same in the repository and on the firewall */
    $shipped = json_decode((string)file_get_contents(
        __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/gaming.json'), true);
    $keys = array_keys((array)($shipped['servers'] ?? []));
    $model = new ParentalControl();
    (new M1_4_0())->run($model);
    check('migration 1.4.0: offers every shipped game to the list',
        [count($keys) > 0, array_keys($model->offered ?? [])],
        [true, $keys]);
    $xml = simplexml_load_file(__DIR__ . '/../src/opnsense/mvc/app/models/OPNsense/ParentalControl/ParentalControl.xml');
    check('migration 1.4.0: the model is 1.4.0', $xml === false ? null : (string)$xml->version, '1.4.0');

    finish();
}
