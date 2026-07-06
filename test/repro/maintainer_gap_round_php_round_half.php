<?php

declare(strict_types=1);

echo round(2.5, 0, PHP_ROUND_HALF_UP), "\n";
echo round(2.5, 0, PHP_ROUND_HALF_DOWN), "\n";
echo round(3.5, 0, PHP_ROUND_HALF_EVEN), "\n";
echo round(2.5, mode: PHP_ROUND_HALF_UP), "\n";
