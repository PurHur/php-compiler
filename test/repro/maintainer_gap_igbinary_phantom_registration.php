<?php

declare(strict_types=1);

if (extension_loaded('igbinary')) {
    echo "fail: extension_loaded('igbinary') true on reference profile\n";
    exit(1);
}

if (function_exists('igbinary_serialize')) {
    echo "fail: function_exists('igbinary_serialize') true on reference profile\n";
    exit(1);
}

echo "ok\n";
