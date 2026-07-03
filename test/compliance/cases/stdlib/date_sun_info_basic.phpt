--TEST--
stdlib date_sun_info() — sun/twilight timestamps (ext/date/php_date.c, #6831)
--FILE--
<?php
$info = date_sun_info(1718121600, 48.8566, 2.3522);
echo $info['sunrise'], "\n";
echo $info['sunset'], "\n";
echo $info['transit'], "\n";
echo $info['civil_twilight_begin'], "\n";
echo $info['civil_twilight_end'], "\n";
echo $info['nautical_twilight_begin'], "\n";
echo $info['nautical_twilight_end'], "\n";
echo $info['astronomical_twilight_begin'], "\n";
echo $info['astronomical_twilight_end'], "\n";
--EXPECT--
1718077492
1718135752
1718106622
1718075077
1718138166
1718071493
1718141751
1718063729
1718149515
