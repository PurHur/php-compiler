<?php
declare(strict_types=1);
// Repro #23936 — DateTimeImmutable::modify / DateInterval::createFromDateString relative grammar
$base = new DateTimeImmutable('2024-03-31 12:00:00');
foreach ([
    'last day of previous month' => '2024-02-29 12:00:00',
    'first day of January next year' => '2025-01-01 12:00:00',
    'monday this week' => '2024-03-25 00:00:00',
    'back of 9am' => '2024-03-31 09:15:00',
    'front of 5pm' => '2024-03-31 16:45:00',
    'first day of next month' => '2024-04-01 12:00:00',
] as $s => $expect) {
    $r = @$base->modify($s);
    $got = false === $r ? 'false' : $r->format('Y-m-d H:i:s');
    echo $s, ' => ', $got, ($got === $expect ? ' OK' : ' FAIL want '.$expect), "\n";
}
$i = @DateInterval::createFromDateString('last day of next month');
echo 'interval last day of next month => ', (false === $i ? 'false' : 'm='.$i->m), "\n";
$i2 = @DateInterval::createFromDateString('next Monday');
echo 'interval next Monday => ', (false === $i2 ? 'false' : 'ok'), "\n";
