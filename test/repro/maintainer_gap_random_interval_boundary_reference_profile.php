<?php

declare(strict_types=1);

if (enum_exists('Random\IntervalBoundary', false)) {
    fwrite(STDERR, "fail: Random\\IntervalBoundary registered on Zend 8.2 reference profile\n");
    exit(1);
}

echo "ok\n";
