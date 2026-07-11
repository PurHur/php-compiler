<?php

declare(strict_types=1);

if (extension_loaded('brotli')) {
    fwrite(STDERR, "FAIL: extension_loaded('brotli') true on reference profile\n");
    exit(1);
}
foreach (['brotli_compress', 'brotli_uncompress'] as $fn) {
    if (function_exists($fn)) {
        fwrite(STDERR, "FAIL: function_exists('{$fn}') true on reference profile\n");
        exit(1);
    }
}

echo "ok\n";
