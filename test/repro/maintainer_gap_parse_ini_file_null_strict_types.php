<?php

declare(strict_types=1);

// Repro for #12667 — parse_ini_file(null) under strict_types must TypeError (php-src ini.c).
try {
    parse_ini_file(null);
    echo "fail: parse_ini_file(null) expected TypeError under strict_types\n";
    exit(1);
} catch (TypeError $e) {
    if ('parse_ini_file(): Argument #1 ($filename) must be of type string, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok\n";
    exit(0);
}
