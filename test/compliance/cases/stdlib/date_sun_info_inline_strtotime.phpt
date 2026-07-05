--TEST--
stdlib date_sun_info() inline strtotime() matches variable form (re-#11336, lib/Compiler.php)
--FILE--
<?php
$inline = date_sun_info(strtotime('2020-06-21'), 51.5, -0.1);
$t = strtotime('2020-06-21');
$var = date_sun_info($t, 51.5, -0.1);
echo ($inline['sunrise'] === $var['sunrise'] && $inline['sunset'] === $var['sunset']) ? "ok\n" : "fail\n";
--EXPECT--
ok
