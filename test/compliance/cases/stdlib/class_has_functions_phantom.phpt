--TEST--
stdlib class_has_method/property/constant() — never advertised (php-src-strict; #16664, #28413)
--FILE--
<?php
echo function_exists('class_has_method') ? "chm_fail\n" : "chm_ok\n";
echo function_exists('class_has_property') ? "chp_fail\n" : "chp_ok\n";
echo function_exists('class_has_constant') ? "chc_fail\n" : "chc_ok\n";
--EXPECT--
chm_ok
chp_ok
chc_ok
