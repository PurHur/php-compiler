<?php

declare(strict_types=1);

if (!function_exists('header_list')) {
    echo "fail: header_list not registered on forward 8.4 profile\n";
    exit(1);
}

echo "ok\n";
