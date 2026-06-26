<?php

declare(strict_types=1);

if (!function_exists('readline')) {
    echo "fail: function_exists('readline') false\n";
    exit(1);
}

if (!extension_loaded('readline')) {
    echo "fail: extension_loaded('readline') false while readline() is registered\n";
    exit(1);
}

echo "ok\n";
