<?php

declare(strict_types=1);

if (extension_loaded('tidy')) {
    fwrite(STDERR, "FAIL: extension_loaded('tidy') true on reference profile\n");
    exit(1);
}
if (function_exists('tidy_parse_string')) {
    fwrite(STDERR, "FAIL: function_exists('tidy_parse_string') true on reference profile\n");
    exit(1);
}
if (class_exists('tidy', false)) {
    fwrite(STDERR, "FAIL: class_exists('tidy') true on reference profile\n");
    exit(1);
}

echo "ok\n";
