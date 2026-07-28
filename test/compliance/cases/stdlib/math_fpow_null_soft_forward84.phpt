--TEST--
stdlib fpow(null, …) E_DEPRECATED + coerce on 8.4 forward profile (#24177, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$result = fpow(null, 1.0);
echo 'fpow(null)=', var_export($result, true), "\n";
--EXPECTF--
PHP Deprecated:  fpow(): Passing null to parameter #1 ($num) of type float is deprecated in %s on line %d
fpow(null)=0.0
