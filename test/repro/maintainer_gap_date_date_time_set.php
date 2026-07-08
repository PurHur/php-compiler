<?php

declare(strict_types=1);

$dt = date_create('2024-01-15 10:30:00');
date_date_set($dt, 2025, 6, 8);
if ('2025-06-08 10:30:00' !== $dt->format('Y-m-d H:i:s')) {
    echo 'fail: date_date_set got ', $dt->format('Y-m-d H:i:s'), "\n";
    exit(1);
}

date_time_set($dt, 14, 45, 30);
if ('2025-06-08 14:45:30' !== $dt->format('Y-m-d H:i:s')) {
    echo 'fail: date_time_set got ', $dt->format('Y-m-d H:i:s'), "\n";
    exit(1);
}

date_time_set($dt, 9, 0);
if ('2025-06-08 09:00:00' !== $dt->format('Y-m-d H:i:s')) {
    echo 'fail: date_time_set default second got ', $dt->format('Y-m-d H:i:s'), "\n";
    exit(1);
}

echo "ok\n";
