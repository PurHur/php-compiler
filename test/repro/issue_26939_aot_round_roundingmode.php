<?php

/**
 * #26939 — AOT round() with RoundingMode must compile and match VM (re-#26800).
 * Requires PHP_COMPILER_PROFILE=8.4 for RoundingMode registration.
 */
echo round(1.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(1.5, 0, RoundingMode::HalfTowardsZero), "\n";
