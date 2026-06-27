<?php

declare(strict_types=1);

if (enum_exists('RoundingMode', false)) {
    echo "fail: RoundingMode registered on Zend 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
