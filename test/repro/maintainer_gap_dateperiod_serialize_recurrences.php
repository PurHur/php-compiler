<?php
declare(strict_types=1);

$p = new DatePeriod(new DateTime('2024-01-01'), new DateInterval('P1D'), new DateTime('2024-01-03'));
$s = serialize($p);
if (!preg_match('/recurrences";i:(-?\d+)/', $s, $m)) {
    echo "NO_MATCH\n";
    exit(1);
}
echo 'recurrences=', $m[1], "\n";
