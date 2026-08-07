--TEST--
stdlib round() RoundingMode negatives match Zend (#28534, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo round(-1.6, 0, RoundingMode::TowardsZero), "\n";
echo round(-1.2, 0, RoundingMode::PositiveInfinity), "\n";
echo round(-1.5, 0, RoundingMode::HalfTowardsZero), "\n";
echo round(-1.5, 0, RoundingMode::HalfOdd), "\n";
echo round(-2.5, 0, RoundingMode::HalfEven), "\n";
echo round(1.5, 0, RoundingMode::HalfTowardsZero), "\n";
echo round(-1.6, 0, RoundingMode::AwayFromZero), "\n";
echo round(-1.6, 0, RoundingMode::NegativeInfinity), "\n";
--EXPECT--
-1
-1
-1
-1
-2
1
-2
-2
