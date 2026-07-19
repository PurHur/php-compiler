<?php
declare(strict_types=1);

try {
    echo abs("5"), "\n";
    echo "FAIL: expected TypeError\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    echo abs(true), "\n";
    echo "FAIL: expected TypeError for bool\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

echo "ok\n";
