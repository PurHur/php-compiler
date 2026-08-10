--TEST--
AOT: DateTimeZone::getOffset() / timezone_offset_get() America/New_York DST (#27308, #29732)
--FILE--
<?php
$z = new DateTimeZone('America/New_York');
$d = new DateTime('2024-07-01 12:00:00', $z);
echo $z->getOffset($d), "\n";
echo timezone_offset_get($z, $d), "\n";
$utc = new DateTimeZone('UTC');
$dUtc = new DateTimeImmutable('2020-01-01', $utc);
echo $utc->getOffset($dUtc), "\n";
--EXPECT--
-14400
-14400
0
