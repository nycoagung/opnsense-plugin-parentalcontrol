#!/usr/local/bin/php
<?php
/**
 * syncTable(): a pf table gets what it lacks and loses what it should not hold, with
 * one pfctl call for all additions and one for all deletions, so a list of thousands
 * of addresses loads at once; when pfctl cannot run, each address goes through
 * configd as before.
 *
 * Needs plain PHP; no OPNsense: core's configd client is stood in for below, and
 * pfctl by a recorder passed to syncTable().
 *
 *   php tests/FirewallTest.php
 */

namespace OPNsense\Core {
    class Backend
    {
        /** @var list<string> what 'filter list table' answers */
        public array $table = [];
        /** @var list<array{string, list<string>}> every configd call but the listing */
        public array $calls = [];

        public function configdpRun(string $cmd, array $params = []): string
        {
            if ($cmd === 'filter list table') {
                return (string)json_encode(['items' => array_map(fn(string $ip): array => ['ip' => $ip], $this->table)]);
            }
            $this->calls[] = [$cmd, $params];
            return '';
        }
    }
}

namespace {
    $lib = __DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/lib';
    require $lib . '/markers.php';
    require $lib . '/messaging.php';
    require $lib . '/firewall.php';

    use OPNsense\Core\Backend;

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

    /* a pfctl stand-in that records each call and answers $ok */
    function recorder(array &$runs, bool $ok): callable
    {
        return function (string $table, string $op, array $addrs) use (&$runs, $ok): bool {
            $runs[] = [$table, $op, $addrs];
            return $ok;
        };
    }

    $backend = new Backend();
    $backend->table = ['192.0.2.1', '192.0.2.2', '198.51.100.0/24'];
    $runs = [];
    $diff = syncTable($backend, 'T', ['192.0.2.2', '203.0.113.5', '203.0.113.6', '198.51.100.0/24'], true,
        recorder($runs, true));
    check('table: the additions go to pfctl in one call, the deletions in another',
        [$runs, $backend->calls],
        [[['T', 'add', ['203.0.113.5', '203.0.113.6']], ['T', 'delete', ['192.0.2.1']]], []]);
    check('table: the differences are reported',
        $diff,
        ['added' => ['203.0.113.5', '203.0.113.6'], 'removed' => ['192.0.2.1']]);

    $backend = new Backend();
    $backend->table = ['192.0.2.1'];
    $runs = [];
    syncTable($backend, 'T', ['203.0.113.5', '203.0.113.6'], true, recorder($runs, false));
    check('table: when pfctl fails, each address goes through configd',
        $backend->calls,
        [['filter add table', ['T', '203.0.113.5']], ['filter add table', ['T', '203.0.113.6']],
         ['filter delete table', ['T', '192.0.2.1']]]);

    $backend = new Backend();
    $backend->table = ['192.0.2.1'];
    $runs = [];
    $diff = syncTable($backend, 'T', ['203.0.113.5'], false, recorder($runs, true));
    check('table: with adding off only deletions run, and nothing is reported added',
        [$runs, $diff],
        [[['T', 'delete', ['192.0.2.1']]], ['added' => [], 'removed' => ['192.0.2.1']]]);

    $backend = new Backend();
    $backend->table = ['192.0.2.1'];
    $runs = [];
    syncTable($backend, 'T', ['192.0.2.1'], true, recorder($runs, true));
    check('table: nothing to change runs nothing', [$runs, $backend->calls], [[], []]);

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
