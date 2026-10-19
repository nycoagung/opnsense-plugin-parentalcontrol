#!/usr/local/bin/php
<?php
/**
 * syncTable(): a pf table gets what it lacks and loses what it should not hold, with
 * one pfctl call for all additions and one for all deletions, so a list of thousands
 * of addresses loads at once. Every entry is checked and put in pf's own form first,
 * so one bad entry cannot fail the whole call; a failed call is reported, and only a
 * small one goes through configd address by address.
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

    /* a pfctl stand-in that records each call and answers null (it ran) or $error */
    function recorder(array &$runs, ?string $error = null): callable
    {
        return function (string $table, string $op, array $addrs) use (&$runs, $error): ?string {
            $runs[] = [$table, $op, $addrs];
            return $error;
        };
    }

    $backend = new Backend();
    $backend->table = ['192.0.2.1', '192.0.2.2', '198.51.100.0/24'];
    $runs = [];
    $diff = syncTable($backend, 'T', ['192.0.2.2', '203.0.113.5', '203.0.113.6', '198.51.100.0/24'], true,
        recorder($runs));
    check('table: the additions go to pfctl in one call, the deletions in another',
        [$runs, $backend->calls],
        [[['T', 'add', ['203.0.113.5', '203.0.113.6']], ['T', 'delete', ['192.0.2.1']]], []]);
    check('table: the differences are reported',
        $diff,
        ['added' => ['203.0.113.5', '203.0.113.6'], 'removed' => ['192.0.2.1'], 'errors' => []]);

    $backend = new Backend();
    $backend->table = ['192.0.2.1'];
    $runs = [];
    syncTable($backend, 'T', ['203.0.113.5', '203.0.113.6'], true, recorder($runs, 'pfctl: Invalid argument.'));
    check('table: when pfctl fails on a small change, each address goes through configd',
        $backend->calls,
        [['filter add table', ['T', '203.0.113.5']], ['filter add table', ['T', '203.0.113.6']],
         ['filter delete table', ['T', '192.0.2.1']]]);

    $backend = new Backend();
    $backend->table = ['192.0.2.1'];
    $runs = [];
    $diff = syncTable($backend, 'T', ['203.0.113.5'], false, recorder($runs));
    check('table: with adding off only deletions run, and nothing is reported added',
        [$runs, $diff],
        [[['T', 'delete', ['192.0.2.1']]], ['added' => [], 'removed' => ['192.0.2.1'], 'errors' => []]]);

    $backend = new Backend();
    $backend->table = ['192.0.2.1'];
    $runs = [];
    syncTable($backend, 'T', ['192.0.2.1'], true, recorder($runs));
    check('table: nothing to change runs nothing', [$runs, $backend->calls], [[], []]);

    /* pf refuses an entry with host bits set, and one refusal fails the whole call */
    $backend = new Backend();
    $runs = [];
    $diff = syncTable($backend, 'T', ['192.0.2.5/24', '203.0.113.7/32', '203.0.113.7'], true, recorder($runs));
    check('table: entries go to pfctl in pf\'s own form, each once',
        [$runs, $diff['added']],
        [[['T', 'add', ['192.0.2.0/24', '203.0.113.7']]], ['192.0.2.0/24', '203.0.113.7']]);

    /* pfctl reads a name or interface as something to look up or expand */
    $backend = new Backend();
    $runs = [];
    $diff = syncTable($backend, 'T', ['self', 'igb1:network', '203.0.113.9', '1.2.3.4 lo0', '192.0.2.1/33'], true,
        recorder($runs));
    check('table: anything not an IPv4 address or network is left out and reported',
        [$runs, $diff['errors']],
        [[['T', 'add', ['203.0.113.9']]], [
            "table T: 'self' is not an IPv4 address or network; left out",
            "table T: 'igb1:network' is not an IPv4 address or network; left out",
            "table T: '1.2.3.4 lo0' is not an IPv4 address or network; left out",
            "table T: '192.0.2.1/33' is not an IPv4 address or network; left out",
        ]]);

    $backend = new Backend();
    $runs = [];
    $diff = syncTable($backend, 'T', ['203.0.113.5', '203.0.113.6'], true, recorder($runs, 'pfctl: Invalid argument.'));
    check('table: a failed pfctl call is reported with its message',
        $diff['errors'],
        ['table T: pfctl add of 2 entries failed: pfctl: Invalid argument.']);

    /* address by address, thousands of entries hold the sync lock for minutes */
    $backend = new Backend();
    $runs = [];
    $many = array_map(fn(int $i): string => "203.0.113.$i", range(1, 65));
    $diff = syncTable($backend, 'T', $many, true, recorder($runs, 'pfctl: Invalid argument.'));
    check('table: a failed load of more than 64 entries is not repeated through configd',
        [$backend->calls, $diff['errors']],
        [[], ['table T: pfctl add of 65 entries failed: pfctl: Invalid argument.; left for the next run']]);

    $backend = new Backend();
    $runs = [];
    $diff = syncTable($backend, 'T', array_slice($many, 0, 64), true, recorder($runs, 'pfctl: Invalid argument.'));
    check('table: a failed load of 64 entries still goes through configd',
        count($backend->calls), 64);

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
