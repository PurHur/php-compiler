<?php
// AOT compile-only (#4043): DateTime/DateTimeImmutable::format() JIT lowering.
$dt = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo $dt->format('Y-m-d'), "\n";
$di = new DateTimeImmutable('2024-06-05 08:00:00', new DateTimeZone('UTC'));
echo $di->format('Y-m-d H:i:s'), "\n";
