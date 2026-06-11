--TEST--
AOT: date_sun_info() returns twilight timestamps for fixed coordinates (#6831)
--FILE--
<?php
$info = date_sun_info(1718121600, 48.8566, 2.3522);
echo $info['sunrise'], "\n";
echo $info['sunset'], "\n";
--EXPECT--
1718077492
1718135752
