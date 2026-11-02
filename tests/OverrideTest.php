#!/usr/local/bin/php
<?php
/**
 * setOverrideAction(), the call behind the dashboard widget's switch: it saves the
 * override and starts the sync in the background, so it answers at once. A sync can
 * run for many seconds while devices use the messaging or gaming block, and the
 * dashboard gives a request 5 seconds before it sends it again.
 *
 * Needs plain PHP; no OPNsense: the controller's base class, its request, its model
 * and core's configd client are stood in for below.
 *
 *   php tests/OverrideTest.php
 */

namespace OPNsense\Core {
    class Backend
    {
        /** @var list<array{string, bool}> every configd call, with its detach flag */
        public static array $calls = [];

        public function configdRun($event, $detach = false, $timeout = 120, $connect_timeout = 10)
        {
            self::$calls[] = [$event, $detach];
            return $detach ? '' : 'OK';
        }
    }
}

namespace OPNsense\Base {
    class ApiMutableModelControllerBase
    {
        public $request;
        public $model;
        public int $saves = 0;

        public function getModel()
        {
            return $this->model;
        }

        protected function save()
        {
            $this->saves++;
            return ['result' => 'saved'];
        }
    }
}

namespace {
    require __DIR__ . '/../src/opnsense/mvc/app/controllers/OPNsense/ParentalControl/Api/SettingsController.php';

    use OPNsense\Core\Backend;
    use OPNsense\ParentalControl\Api\SettingsController;

    final class FakeRequest
    {
        public function __construct(private bool $post, private array $fields) {}
        public function isPost(): bool { return $this->post; }
        public function getPost($name, $filter = null, $default = null) { return $this->fields[$name] ?? $default; }
    }

    final class FakeModel
    {
        /** @var array<string, object> devices by uuid */
        public array $devices = [];
        public function getNodeByReference(string $ref) { return $this->devices[substr($ref, strlen('devices.'))] ?? null; }
        public function performValidation() { return new ArrayObject([]); }
    }

    /** a controller with one device, asked by POST (or not) with these fields */
    function controller(bool $post, array $fields): SettingsController
    {
        $c = new SettingsController();
        $c->request = new FakeRequest($post, $fields);
        $c->model = new FakeModel();
        $c->model->devices['dev-1'] = (object)['override' => 'none'];
        Backend::$calls = [];
        return $c;
    }

    $pass = $fail = 0;
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        if ($ok) {
            $pass++;
            printf("  pass  %s\n", $name);
        } else {
            $fail++;
            printf("  FAIL  %s  :: %s\n", $name, $detail);
        }
    }

    $c = controller(true, ['override' => 'allow']);
    $out = $c->setOverrideAction('dev-1');
    check('a POST saves the override and says so', $out === ['result' => 'saved', 'override' => 'allow']
        && $c->model->devices['dev-1']->override === 'allow' && $c->saves === 1, json_encode([$out, $c->saves]));
    check('the sync starts in the background, so the call answers at once',
        Backend::$calls === [['parentalcontrol sync', true]], json_encode(Backend::$calls));

    foreach (['block', 'none'] as $value) {
        $c = controller(true, ['override' => $value]);
        $c->setOverrideAction('dev-1');
        check("override '$value' is saved and synced in the background",
            $c->model->devices['dev-1']->override === $value && Backend::$calls === [['parentalcontrol sync', true]],
            json_encode(Backend::$calls));
    }

    $refused = [
        'a GET'           => [false, ['override' => 'allow'], 'dev-1'],
        'an unknown value' => [true, ['override' => 'maybe'], 'dev-1'],
        'an unknown device' => [true, ['override' => 'allow'], 'dev-9'],
    ];
    foreach ($refused as $name => [$post, $fields, $uuid]) {
        $c = controller($post, $fields);
        $out = $c->setOverrideAction($uuid);
        check("$name is refused, with nothing saved or synced", ($out['result'] ?? '') === 'failed'
            && $c->saves === 0 && Backend::$calls === [] && $c->model->devices['dev-1']->override === 'none',
            json_encode([$out, $c->saves, Backend::$calls]));
    }

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
