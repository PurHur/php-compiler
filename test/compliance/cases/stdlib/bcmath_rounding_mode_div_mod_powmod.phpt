--TEST--
stdlib bcdiv/bcmod/bcpowmod reject phantom rounding_mode — Zend scale-only (#26143, reverts #9919)
--FILE--
<?php
echo bcdiv('10', '3', 2), "\n";
echo bcmod('10.5', '3.2', 1), "\n";
echo bcpowmod('2', '10', '1000', 0), "\n";
try {
    echo bcdiv('10', '3', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    echo bcpowmod('2', '10', '1000', 0, RoundingMode::HalfAwayFromZero), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
3.33
0.9
24
Error
ArgumentCountError
