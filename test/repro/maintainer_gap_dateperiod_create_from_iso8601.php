<?php

declare(strict_types=1);

$p = DatePeriod::createFromISO8601String('2024-01-01T00:00:00/2024-01-03T00:00:00/P1D');
foreach ($p as $d) {
    echo $d->format('Y-m-d'), "\n";
    break;
}
