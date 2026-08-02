<?php

/**
 * #26800 — AOT round() with RoundingMode must match VM/JIT (not print 0).
 * Requires PHP_COMPILER_PROFILE=8.4 for RoundingMode registration.
 */
echo round(1.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(-1.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(1.55, 1, RoundingMode::HalfEven), "\n";
echo round(1.5), "\n";
