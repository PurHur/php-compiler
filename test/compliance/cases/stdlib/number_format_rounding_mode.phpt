--TEST--
stdlib number_format() optional rounding_mode (PHP 8.4, ext/standard/number_format.c, #9438)
--FILE--
<?php
declare(strict_types=1);

var_export(number_format(2.5, 0, '.', '', RoundingMode::HalfAwayFromZero));
echo "\n";
var_export(number_format(2.5, 0, '.', '', RoundingMode::TowardsZero));
echo "\n";
var_export(number_format(1.55, 1, '.', '', RoundingMode::HalfAwayFromZero));
echo "\n";
--EXPECT--
'3'
'2'
'1.6'
