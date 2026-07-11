<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$dt = new DateTime('2024-01-31');
$dt->modify('next month');
if ('2024-03-02' !== $dt->format('Y-m-d')) {
    echo 'fail: next month got ', $dt->format('Y-m-d'), " expected 2024-03-02\n";
    exit(1);
}

$dt2 = new DateTime('2024-01-31');
$dt2->modify('+1 month');
if ('2024-03-02' !== $dt2->format('Y-m-d')) {
    echo 'fail: +1 month got ', $dt2->format('Y-m-d'), " expected 2024-03-02\n";
    exit(1);
}

$dt3 = new DateTime('2024-03-31');
$dt3->modify('last month');
if ('2024-03-02' !== $dt3->format('Y-m-d')) {
    echo 'fail: last month got ', $dt3->format('Y-m-d'), " expected 2024-03-02\n";
    exit(1);
}

$dt4 = new DateTime('2024-01-31');
$dt4->modify('this month');
if ('2024-01-31' !== $dt4->format('Y-m-d')) {
    echo 'fail: this month got ', $dt4->format('Y-m-d'), " expected 2024-01-31\n";
    exit(1);
}

echo "ok\n";
