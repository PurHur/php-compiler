--TEST--
stdlib fdiv() optional rounding_mode (PHP 8.4, ext/standard/math.c, #9918)
--FILE--
<?php
declare(strict_types=1);

var_export(fdiv(10.0, 3.0));
echo "\n";
var_export(fdiv(10.0, 3.0, rounding_mode: RoundingMode::TowardsZero));
echo "\n";
--EXPECT--
3.3333333333333335
3.0
