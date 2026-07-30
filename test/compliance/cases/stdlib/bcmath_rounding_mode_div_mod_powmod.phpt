--TEST--
stdlib bcdiv/bcmod/bcpowmod optional rounding_mode — PHP 8.4 (ext/bcmath/bcmath.c, #9919)
--FILE--
<?php
echo bcdiv('10', '3', 2), "\n";
echo bcdiv('10', '3', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
echo bcmod('10.5', '3.2', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
echo bcpowmod('2', '10', '1000', 0, RoundingMode::HalfAwayFromZero), "\n";
--EXPECT--
3.33
3
0
24
