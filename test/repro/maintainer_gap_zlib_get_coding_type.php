<?php

declare(strict_types=1);

if (!function_exists('zlib_get_coding_type')) {
    fwrite(STDERR, "zlib_get_coding_type() missing\n");
    exit(1);
}
echo 'coding_type=';
var_export(zlib_get_coding_type());
echo "\n";
echo "ok\n";
