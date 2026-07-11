--TEST--
stdlib class_has_method/property/constant() — not advertised on PHP 8.2 reference profile (#16664)
--FILE--
<?php
echo function_exists('class_has_method') ? "chm_fail\n" : "chm_ok\n";
echo function_exists('class_has_property') ? "chp_fail\n" : "chp_ok\n";
echo function_exists('class_has_constant') ? "chc_fail\n" : "chc_ok\n";
--EXPECT--
chm_ok
chp_ok
chc_ok
