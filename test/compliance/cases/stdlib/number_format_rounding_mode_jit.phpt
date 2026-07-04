--TEST--
stdlib number_format() optional rounding_mode JIT (#9438, ext/standard/number_format.c)
--FILE--
<?php
declare(strict_types=1);

echo number_format(2.5, 0, '.', '', RoundingMode::HalfAwayFromZero), "\n";
echo number_format(2.5, 0, '.', '', RoundingMode::TowardsZero), "\n";
--EXPECT--
3
2
