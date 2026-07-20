--TEST--
stdlib fdiv/fmod/hypot/atan2(null, …) E_DEPRECATED + coerce on 8.4 (#20432, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
foreach (['fdiv', 'fmod', 'hypot', 'atan2'] as $fn) {
    $result = $fn(null, 1.0);
    echo $fn, '(null)=', var_export($result, true), "\n";
}
--EXPECTF--
PHP Deprecated:  fdiv(): Passing null to parameter #1 ($num1) of type float is deprecated in %s on line %d
PHP Deprecated:  fmod(): Passing null to parameter #1 ($num1) of type float is deprecated in %s on line %d
PHP Deprecated:  hypot(): Passing null to parameter #1 ($x) of type float is deprecated in %s on line %d
PHP Deprecated:  atan2(): Passing null to parameter #1 ($y) of type float is deprecated in %s on line %d
fdiv(null)=0.0
fmod(null)=0.0
hypot(null)=1.0
atan2(null)=0.0
