<?php

declare(strict_types=1);

// #23980 — number_format() must emit unsigned zero after round-to-zero
// (php-src ext/standard/math.c _php_math_number_format_ex).
echo number_format(-0.004, 2), "\n";
echo number_format(-0.0004, 2), "\n";
echo number_format(-0.4, 0), "\n";
echo number_format(-0.5, 2), "\n";
echo number_format(-0.004, 2, ',', ' '), "\n";
