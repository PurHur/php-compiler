<?php

declare(strict_types=1);

if (function_exists('header_list')) {
    echo "fail: header_list registered on reference profile\n";
    exit(1);
}

if (function_exists('header')) {
    echo "header_ok\n";
} else {
    echo "header_missing\n";
    exit(1);
}

echo "ok\n";
