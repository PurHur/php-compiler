<?php

declare(strict_types=1);

/**
 * Repro #12949 — RoundingMode builtin enum on 8.4 forward profile.
 */

if (!enum_exists('RoundingMode', false)) {
    echo "missing\n";
    exit(1);
}

echo round(2.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(2.5, 0, RoundingMode::TowardsZero), "\n";
echo "ok\n";
