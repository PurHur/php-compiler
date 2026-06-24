--TEST--
stdlib get_browser() registered — false when browscap ini unset (#11172, ext/standard/browscap.c)
--FILE--
<?php
echo function_exists('get_browser') ? "exists\n" : "missing\n";
var_export(@get_browser(null));
echo "\n";
--EXPECT--
exists
false
