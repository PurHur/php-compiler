<?php

declare(strict_types=1);

// Issue #9919 — bcdiv/bcmod/bcpowmod optional rounding_mode (PHP 8.4, ext/bcmath/bcmath.c).

echo bcdiv('10', '3', 2), "\n";
echo bcdiv('10', '3', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
echo bcmod('10.5', '3.2', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
echo bcpowmod('2', '10', '1000', 0, RoundingMode::HalfAwayFromZero), "\n";
