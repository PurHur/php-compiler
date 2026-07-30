--TEST--
stdlib bcadd/bcsub/bcmul optional rounding_mode — PHP 8.4 (ext/bcmath/bcmath.c, #9946)
--FILE--
<?php
echo bcadd('1.234', '0.005', 2, RoundingMode::TowardsZero), "\n";
echo bcadd('1.005', '0.004', 2), "\n";
echo bcsub('3.00', '0.004', 2, RoundingMode::TowardsZero), "\n";
echo bcmul('1.55', '1.55', 2, RoundingMode::TowardsZero), "\n";
try {
    bcadd('1', '2', 0, 99);
    echo "invalid mode uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
1.23
1.00
2.99
2.40
ValueError
