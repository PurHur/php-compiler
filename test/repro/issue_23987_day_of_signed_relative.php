<?php
/**
 * #23987 — first|last day of ±N month|year (strtotime + modify).
 * Base: 2024-01-31 12:00:00
 */
$baseTs = strtotime('2024-01-31 12:00:00');
$expect = [
    'first day of +1 month' => '2024-02-01 12:00:00',
    'last day of +1 month' => '2024-02-29 12:00:00',
    'first day of +2 months' => '2024-03-01 12:00:00',
    'last day of -1 month' => '2023-12-31 12:00:00',
    'first day of +1 year' => '2025-01-01 12:00:00',
    'first day of 1 month' => '2024-02-01 12:00:00',
    'last day of +1 year' => '2025-01-31 12:00:00',
];
foreach ($expect as $phrase => $want) {
    $t = @strtotime($phrase, $baseTs);
    $got = false === $t ? 'false' : date('Y-m-d H:i:s', $t);
    echo 'strtotime ', $phrase, ' => ', $got, ($got === $want ? " OK\n" : " WANT $want\n");
}

$base = new DateTimeImmutable('2024-01-31 12:00:00');
foreach (['first day of +1 month', 'last day of +1 month'] as $phrase) {
    $r = @$base->modify($phrase);
    $got = false === $r ? 'false' : $r->format('Y-m-d H:i:s');
    $want = $expect[$phrase];
    echo 'modify ', $phrase, ' => ', $got, ($got === $want ? " OK\n" : " WANT $want\n");
}

$t = @strtotime('2024-01-31 12:00:00 first day of +1 month');
$got = false === $t ? 'false' : date('Y-m-d H:i:s', $t);
echo 'absolute+suffix => ', $got, ($got === '2024-02-01 12:00:00' ? " OK\n" : " WANT 2024-02-01 12:00:00\n");
