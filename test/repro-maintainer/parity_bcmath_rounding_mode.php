<?php

declare(strict_types=1);

// Issue #26143 — procedural bc* reject rounding_mode; bcround keeps RoundingMode.
// (Supersedes #9919 phantom API; php-src bcmath.stub.php.)

echo bcdiv('10', '3', 2), "\n";
try {
    echo bcdiv('10', '3', rounding_mode: RoundingMode::HalfAwayFromZero), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
echo bcround('1.55', 1, RoundingMode::HalfAwayFromZero), "\n";
