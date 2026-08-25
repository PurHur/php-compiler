<?php
// #34594 — AOT unserialize(serialize(DateTime*)) via runtime string (peer #34576 fold-only gap).
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

// File-backed wire — no compileTimeString (#34594 NestedJIT restore).
file_put_contents(sys_get_temp_dir().'/phpc_34594.ser', serialize(new DateTime('2021-06-15', new DateTimeZone('UTC'))));
echo unserialize(file_get_contents(sys_get_temp_dir().'/phpc_34594.ser'))->format('Y-m-d'), PHP_EOL;
