<?php

declare(strict_types=1);

if (function_exists('clock_gettime')) {
    echo "fail: clock_gettime registered on reference profile\n";
    exit(1);
}

if (enum_exists('ClockInterface')) {
    echo "fail: ClockInterface registered on reference profile\n";
    exit(1);
}

echo "ok\n";
