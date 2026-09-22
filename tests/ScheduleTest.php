#!/usr/local/bin/php
<?php
/**
 * Table-driven tests for the schedule decision.
 *
 * Runs anywhere PHP does - no OPNsense, no firewall, no clock. The logic these
 * cover is the entire product, and its failure modes are silent and asymmetric:
 * a child gets internet at 2am, or an adult loses it mid-call.
 */

/* the function under test, loaded without running the enforcement script */
$src = file_get_contents(__DIR__ . '/../src/opnsense/scripts/OPNsense/ParentalControl/sync.php');
preg_match('/function scheduleDecision\(.*?\n\}/s', $src, $m) || exit("could not extract scheduleDecision\n");
eval($m[0]);

$ALL = ['mon','tue','wed','thu','fri','sat','sun'];
$T = function ($h, $i = 0) { return $h * 60 + $i; };

$cases = [
    // name, days, now, from, to, today, yesterday, expectBlocked
    ['normal window, inside',        $ALL, $T(12), $T(7),  $T(21), 'wed','tue', false],
    ['normal window, before',        $ALL, $T(6),  $T(7),  $T(21), 'wed','tue', true ],
    ['normal window, after',         $ALL, $T(22), $T(7),  $T(21), 'wed','tue', true ],
    ['normal window, at start',      $ALL, $T(7),  $T(7),  $T(21), 'wed','tue', false],
    ['normal window, at end',        $ALL, $T(21), $T(7),  $T(21), 'wed','tue', true ],

    // the overnight bug: "Friday 21:00-07:00" must mean Fri night -> Sat morning
    ['overnight, Fri evening',       ['fri'], $T(22), $T(21), $T(7), 'fri','thu', false],
    ['overnight, Sat morning tail',  ['fri'], $T(3),  $T(21), $T(7), 'sat','fri', false],
    ['overnight, Fri morning',       ['fri'], $T(3),  $T(21), $T(7), 'fri','thu', true ],
    ['overnight, Sat evening',       ['fri'], $T(22), $T(21), $T(7), 'sat','fri', true ],
    ['overnight, midday blocked',    ['fri'], $T(12), $T(21), $T(7), 'fri','thu', true ],
    ['overnight all days, 03:00',    $ALL, $T(3),  $T(21), $T(7), 'sat','fri', false],

    // fail-closed cases
    ['no times set',                 $ALL, $T(12), null,   null,   'wed','tue', true ],
    ['only from set',                $ALL, $T(12), $T(7),  null,   'wed','tue', true ],
    ['no weekdays selected',         [],   $T(12), $T(7),  $T(21), 'wed','tue', true ],
    ['day not selected',             ['mon'], $T(12), $T(7), $T(21), 'wed','tue', true ],

    // boundaries
    ['from == to means all day',     $ALL, $T(3),  $T(7),  $T(7),  'wed','tue', false],
    ['midnight start, inside',       $ALL, $T(0),  $T(0),  $T(6),  'wed','tue', false],
    ['23:59 inside overnight',       $ALL, $T(23,59), $T(21), $T(7), 'wed','tue', false],
    ['00:00 inside overnight tail',  $ALL, $T(0),  $T(21), $T(7), 'thu','wed', false],
];

$pass = $fail = 0;
foreach ($cases as $c) {
    list($name, $days, $now, $from, $to, $today, $yest, $want) = $c;
    list($got, $reason) = scheduleDecision($days, $now, $from, $to, $today, $yest);
    if ($got === $want) {
        $pass++;
        printf("  pass  %-32s %s\n", $name, $reason);
    } else {
        $fail++;
        printf("  FAIL  %-32s expected blocked=%s got blocked=%s (%s)\n",
               $name, var_export($want, true), var_export($got, true), $reason);
    }
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
