--TEST--
stdlib sqrt()/sin(null) E_DEPRECATED + coerce on 8.4 forward profile (#20432, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
foreach (['sqrt', 'sin'] as $fn) {
    $result = $fn(null);
    echo $fn, '(null)=', var_export($result, true), "\n";
}
--EXPECTF--
PHP Deprecated:  sqrt(): Passing null to parameter #1 ($num) of type float is deprecated in %s on line %d
PHP Deprecated:  sin(): Passing null to parameter #1 ($num) of type float is deprecated in %s on line %d
sqrt(null)=0.0
sin(null)=0.0
