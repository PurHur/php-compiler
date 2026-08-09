--TEST--
stdlib fdiv/fmod/hypot/atan2(null) E_DEPRECATED + coerce on 8.4 JIT (#29319, re-#24198; ext/standard/math.c)
--JIT--
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$cases = [
    ['fmod', 5.0, null],
    ['fmod', null, 2.0],
    ['fdiv', 5.0, null],
    ['fdiv', null, 2.0],
    ['hypot', null, 3.0],
    ['atan2', null, 1.0],
];
foreach ($cases as [$fn, $a, $b]) {
    $result = $fn($a, $b);
    $out = is_nan($result) ? 'NAN' : var_export($result, true);
    echo "{$fn}(", var_export($a, true), ',', var_export($b, true), ')=', $out, "\n";
}
--EXPECTF--
PHP Deprecated:  fmod(): Passing null to parameter #2 ($num2) of type float is deprecated in %s on line %d
PHP Deprecated:  fmod(): Passing null to parameter #1 ($num1) of type float is deprecated in %s on line %d
PHP Deprecated:  fdiv(): Passing null to parameter #2 ($num2) of type float is deprecated in %s on line %d
PHP Deprecated:  fdiv(): Passing null to parameter #1 ($num1) of type float is deprecated in %s on line %d
PHP Deprecated:  hypot(): Passing null to parameter #1 ($x) of type float is deprecated in %s on line %d
PHP Deprecated:  atan2(): Passing null to parameter #1 ($y) of type float is deprecated in %s on line %d
fmod(5.0,NULL)=NAN
fmod(NULL,2.0)=0.0
fdiv(5.0,NULL)=INF
fdiv(NULL,2.0)=0.0
hypot(NULL,3.0)=3.0
atan2(NULL,1.0)=0.0
