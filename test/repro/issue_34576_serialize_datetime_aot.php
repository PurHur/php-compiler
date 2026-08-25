<?php
// #34576 — AOT serialize(DateTime/DateTimeImmutable) Zend date/timezone wire (re-#10710).
declare(strict_types=1);

$dt = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo serialize($dt), "\n";
echo unserialize(serialize($dt))->format('c'), "\n";

$dti = new DateTimeImmutable('2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo serialize($dti), "\n";
echo unserialize(serialize($dti))->format('c'), "\n";

$mut = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
$mut->modify('+1 day');
echo serialize($mut), "\n";
echo unserialize(serialize($mut))->format('c'), "\n";
