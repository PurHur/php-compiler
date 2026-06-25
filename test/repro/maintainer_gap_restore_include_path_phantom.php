<?php

declare(strict_types=1);

// Issue #11833 — restore_include_path() removed in PHP 8.0+ (use set_include_path($old) or ini_restore).
if (function_exists('restore_include_path')) {
    echo "fail: function_exists('restore_include_path') true on reference profile\n";
    exit(1);
}

echo "ok\n";
