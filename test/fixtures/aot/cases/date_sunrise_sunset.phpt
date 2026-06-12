--TEST--
AOT: date_sunrise()/date_sunset() procedural solar helpers (#6137)
--FILE--
<?php
$rise = date_sunrise(1782000000, 0, 51.5, -0.1, 90.0, 0.0);
$set = date_sunset(1782000000, 0, 51.5, -0.1, 90.0, 0.0);
echo $rise, "\n";
echo $set, "\n";
--EXPECT--
1782013676
1782072990
