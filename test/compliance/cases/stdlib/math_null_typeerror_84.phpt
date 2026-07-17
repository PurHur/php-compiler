--TEST--
stdlib abs()/round()/ceil()/floor(null) E_DEPRECATED + coerce on 8.4 forward profile (re-#18924, #19981)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
foreach (['abs', 'round', 'ceil', 'floor'] as $fn) {
    $result = $fn(null);
    echo $fn, '(null)=', var_export($result, true), "\n";
}
--EXPECTF--
PHP Deprecated:  abs(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
PHP Deprecated:  round(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
PHP Deprecated:  ceil(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
PHP Deprecated:  floor(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
abs(null)=0
round(null)=0.0
ceil(null)=0.0
floor(null)=0.0
