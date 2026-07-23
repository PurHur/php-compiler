<?php
declare(strict_types=1);

$p = new DatePeriod(new DateTime('2024-01-01'), new DateInterval('P1D'), new DateTime('2024-01-03'));
echo 'class=', get_class($p), "\n";
$p2 = unserialize(serialize($p));
echo 'class=', get_class($p2), "\n";
echo 'start=', $p2->start->format('Y-m-d'), "\n";
$n = 0;
foreach ($p2 as $d) {
    echo 'item=', $d->format('Y-m-d'), "\n";
    $n++;
}
echo 'count=', $n, "\n";
