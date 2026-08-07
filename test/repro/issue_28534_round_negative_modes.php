<?php

/**
 * #28534 — round() RoundingMode modes on negatives must match Zend / bcround.
 */
error_reporting(E_ALL);
echo round(-1.6, 0, RoundingMode::TowardsZero), "\n";
echo round(-1.2, 0, RoundingMode::PositiveInfinity), "\n";
echo round(-1.5, 0, RoundingMode::HalfTowardsZero), "\n";
echo round(-1.5, 0, RoundingMode::HalfOdd), "\n";
echo round(-2.5, 0, RoundingMode::HalfEven), "\n";
echo round(1.5, 0, RoundingMode::HalfTowardsZero), "\n";
echo round(-1.6, 0, RoundingMode::AwayFromZero), "\n";
echo round(-1.6, 0, RoundingMode::NegativeInfinity), "\n";
