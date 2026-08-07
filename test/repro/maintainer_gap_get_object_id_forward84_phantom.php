<?php

declare(strict_types=1);

/**
 * #28405 — get_object_id is a phantom under PROFILE=8.4 (was advertised by #17607).
 * Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php …
 */

if (function_exists('get_object_id')) {
    echo "fail: get_object_id still registered under PROFILE=8.4\n";
    exit(1);
}
if (!function_exists('spl_object_id')) {
    echo "fail: spl_object_id missing\n";
    exit(1);
}

echo "ok\n";
