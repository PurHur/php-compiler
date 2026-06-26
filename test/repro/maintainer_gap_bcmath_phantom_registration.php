<?php

declare(strict_types=1);

if (extension_loaded('bcmath')) {
    echo "fail: extension_loaded('bcmath') true on reference profile\n";
    exit(1);
}

foreach (['bcadd', 'bcsub', 'bcmul', 'bcdiv'] as $fn) {
    if (function_exists($fn)) {
        echo "fail: function_exists('{$fn}') true on reference profile\n";
        exit(1);
    }
}

echo "ok\n";
