<?php

declare(strict_types=1);

if (extension_loaded('bz2')) {
    echo "fail: extension_loaded('bz2') true on reference profile\n";
    exit(1);
}

foreach (['bzcompress', 'bzdecompress'] as $fn) {
    if (function_exists($fn)) {
        echo "fail: function_exists('{$fn}') true on reference profile\n";
        exit(1);
    }
}

echo "ok\n";
