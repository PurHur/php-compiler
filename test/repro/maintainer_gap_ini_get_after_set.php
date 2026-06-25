<?php

declare(strict_types=1);

// Issue #11835 — ini_get('display_errors') after ini_set('0') must return '0' (ext/standard/ini.c).
ini_set('display_errors', '0');
$after = ini_get('display_errors');
echo "after_set=".var_export($after, true)."\n";
if ('0' !== $after) {
    echo "fail: expected '0'\n";
    exit(1);
}
echo "ok\n";
