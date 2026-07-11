--TEST--
stdlib date_sun_info() sunrise/sunset match date_sunrise() (ext/date/php_date.c, #15629)
--FILE--
<?php
$t = strtotime('2020-06-21');
$info = date_sun_info($t, 51.5, -0.1);
echo $info['sunrise'], "\n";
echo $info['sunset'], "\n";
echo date_sunrise($t, SUNFUNCS_RET_TIMESTAMP, 51.5, -0.1), "\n";
echo date_sunset($t, SUNFUNCS_RET_TIMESTAMP, 51.5, -0.1), "\n";
--EXPECT--
1592710857
1592771020
1592710857
1592771020
