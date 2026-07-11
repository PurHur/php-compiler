<?php

declare(strict_types=1);

$base = strtotime('2026-06-30 12:00:00');
$expect = [
    'last monday' => '2026-06-29',
    'next monday' => '2026-07-06',
    'monday' => '2026-07-06',
    'previous monday' => '2026-06-29',
    'this monday' => '2026-07-06',
];
$fail = 0;
foreach ($expect as $s => $want) {
    $st = strtotime($s, $base);
    $strtotime = false !== $st ? date('Y-m-d', $st) : false;
    $dt = date_create($s);
    $create = $dt instanceof DateTimeInterface ? $dt->format('Y-m-d') : null;
    if ($want !== $strtotime || null === $create) {
        echo "FAIL $s create=".var_export($create, true).' strtotime='.var_export($strtotime, true)."\n";
        ++$fail;
    }
}
exit($fail > 0 ? 1 : 0);
