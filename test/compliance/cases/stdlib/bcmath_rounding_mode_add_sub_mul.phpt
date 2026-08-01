--TEST--
stdlib bcadd/bcsub/bcmul reject phantom rounding_mode — Zend scale-only (#26143, reverts #9946)
--FILE--
<?php
echo bcadd('1.005', '0.004', 2), "\n";
echo bcsub('3.00', '0.004', 2), "\n";
echo bcmul('1.55', '1.55', 2), "\n";
try {
    echo bcadd('1.234', '0.005', 2, RoundingMode::TowardsZero), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    echo bcadd('1', '2', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
1.00
2.99
2.40
ArgumentCountError
Error
