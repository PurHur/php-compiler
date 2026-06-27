<?php

declare(strict_types=1);

$dt = new DateTime('2020-01-15 10:30:45', new DateTimeZone('UTC'));
$dt->setDate(2021, 6, 1);
if ('2021-06-01 10:30:45' !== $dt->format('Y-m-d H:i:s')) {
    echo 'fail: setDate mutable ', $dt->format('Y-m-d H:i:s'), "\n";
    exit(1);
}

$dt->setTime(14, 5, 30);
if ('2021-06-01 14:05:30' !== $dt->format('Y-m-d H:i:s')) {
    echo 'fail: setTime mutable ', $dt->format('Y-m-d H:i:s'), "\n";
    exit(1);
}

$immutable = new DateTimeImmutable('2020-01-15 10:30:45', new DateTimeZone('UTC'));
$updated = $immutable->setDate(2021, 6, 1);
if ('2021-06-01' !== $updated->format('Y-m-d')) {
    echo 'fail: setDate immutable ', $updated->format('Y-m-d'), "\n";
    exit(1);
}
if ('2020-01-15' !== $immutable->format('Y-m-d')) {
    echo 'fail: setDate immutable mutated original ', $immutable->format('Y-m-d'), "\n";
    exit(1);
}

echo "ok\n";
