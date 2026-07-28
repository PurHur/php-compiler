<?php
/**
 * #23967 — strtotime / modify short relative forms (php-src parse_date.re).
 * Base: 2020-01-15 12:00:00
 */
$baseTs = strtotime('2020-01-15 12:00:00');
$expect = [
    'last day of' => '2020-01-31 12:00:00',
    'first day of' => '2020-01-01 12:00:00',
    'back of 12' => '2020-01-15 12:15:00',
    'front of 12' => '2020-01-15 11:45:00',
    'last day of this month' => '2020-01-31 12:00:00',
    'first day of this month' => '2020-01-01 12:00:00',
];
foreach ($expect as $phrase => $want) {
    $t = @strtotime($phrase, $baseTs);
    $got = false === $t ? 'false' : date('Y-m-d H:i:s', $t);
    echo 'strtotime ', $phrase, ' => ', $got, ($got === $want ? " OK\n" : " WANT $want\n");
}

$base = new DateTimeImmutable('2020-01-15 12:00:00');
foreach (['last day of', 'first day of', 'back of 12', 'front of 12'] as $phrase) {
    $r = @$base->modify($phrase);
    $got = false === $r ? 'false' : $r->format('Y-m-d H:i:s');
    $want = $expect[$phrase];
    echo 'modify ', $phrase, ' => ', $got, ($got === $want ? " OK\n" : " WANT $want\n");
}
