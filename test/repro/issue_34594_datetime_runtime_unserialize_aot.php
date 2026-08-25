<?php
// #34594 — AOT `$s=serialize($dt); unserialize($s)` must restore Zend date wire (peer #34576).
declare(strict_types=1);

$d = new DateTime('2020-01-02', new DateTimeZone('UTC'));
$s = serialize($d);
$u = unserialize($s);
echo $u->format('Y-m-d'), PHP_EOL;

$di = new DateTimeImmutable('2020-01-02', new DateTimeZone('UTC'));
$si = serialize($di);
$ui = unserialize($si);
echo $ui->format('Y-m-d'), PHP_EOL;

// Folded one-expression path must stay green (#34576).
echo unserialize(serialize(new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'))))->format('Y-m-d'), PHP_EOL;
