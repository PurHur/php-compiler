--TEST--
JIT: fpow() optional rounding_mode (PHP 8.4, ext/standard/math.c, #9990)
--FILE--
<?php
declare(strict_types=1);

var_export(fpow(2.0, 3.0));
echo "\n";
var_export(fpow(10.0, -1.0, rounding_mode: RoundingMode::HalfAwayFromZero));
echo "\n";
--EXPECT--
8.0
0.0
