--TEST--
stdlib date_sunrise()/date_sunset() — procedural solar helpers (#6137)
--FILE--
<?php
$day = gmmktime(0, 0, 0, 6, 21, 2026);
$lat = 51.5;
$lon = -0.1;
$zenith = 90.0;
$gmt = 0.0;
echo function_exists('date_sunrise') ? "sunrise_fn\n" : "missing_sunrise\n";
echo function_exists('date_sunset') ? "sunset_fn\n" : "missing_sunset\n";
$rise = date_sunrise($day, SUNFUNCS_RET_TIMESTAMP, $lat, $lon, $zenith, $gmt);
$set = date_sunset($day, SUNFUNCS_RET_TIMESTAMP, $lat, $lon, $zenith, $gmt);
echo is_int($rise) ? "rise_int\n" : "rise_bad\n";
echo is_int($set) ? "set_int\n" : "set_bad\n";
echo $rise, "\n";
echo $set, "\n";
echo date_sunrise($day, SUNFUNCS_RET_STRING, $lat, $lon, $zenith, $gmt), "\n";
echo defined('SUNFUNCS_RET_TIMESTAMP') ? "const_ok\n" : "const_missing\n";
--EXPECT--
sunrise_fn
sunset_fn
rise_int
set_int
1782013676
1782072990
03:47
const_ok
