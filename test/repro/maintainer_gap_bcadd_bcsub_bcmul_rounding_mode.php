<?php

declare(strict_types=1);

// Issue #9946 — bcadd/bcsub/bcmul optional rounding_mode (PHP 8.4, ext/bcmath/bcmath.c).

echo bcadd('1.234', '0.005', 2, RoundingMode::TowardsZero), "\n";
echo bcadd('1.005', '0.004', 2), "\n";
echo bcsub('3.00', '0.004', 2, RoundingMode::TowardsZero), "\n";
echo bcmul('1.55', '1.55', 2, RoundingMode::TowardsZero), "\n";
