--TEST--
stdlib DateTime construct and format with DateTimeZone UTC
--FILE--
<?php
$dt = new DateTime('2026-05-29 12:00:00', new DateTimeZone('UTC'));
echo $dt->format('Y-m-d'), "\n";
echo $dt->format('c'), "\n";
$ts = $dt->getTimestamp();
echo $ts > 0 ? "ts\n" : "bad\n";
$dt->setTimezone(new DateTimeZone('UTC'));
echo $dt->format('Y-m-d'), "\n";
--EXPECT--
2026-05-29
2026-05-29T12:00:00+00:00
ts
2026-05-29
