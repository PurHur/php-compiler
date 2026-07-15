--TEST--
date: DateTime ctor accepts relative modifier strings like +1 day (#18327)
--FILE--
<?php
declare(strict_types=1);
$dt = new DateTime('+1 day', new DateTimeZone('UTC'));
echo preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt->format('Y-m-d')) ? 'ok' : 'bad', "\n";
$now = new DateTime('now', new DateTimeZone('UTC'));
echo preg_match('/^\d{4}-\d{2}-\d{2}$/', $now->format('Y-m-d')) ? 'ok' : 'bad', "\n";
$abs = new DateTime('2026-07-12', new DateTimeZone('UTC'));
echo $abs->format('Y-m-d'), "\n";
--EXPECT--
ok
ok
2026-07-12
