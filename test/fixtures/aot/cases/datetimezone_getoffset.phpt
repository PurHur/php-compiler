--TEST--
AOT: DateTimeZone::getOffset() / timezone_offset_get() America/New_York DST (#27308)
--FILE--
<?php
$z = new DateTimeZone('America/New_York');
$d = new DateTime('2024-07-01 12:00:00', $z);
echo $z->getOffset($d), "\n";
echo timezone_offset_get($z, $d), "\n";
--EXPECT--
-14400
-14400
