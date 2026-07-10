<?php

declare(strict_types=1);

// Issue #17694 — grapheme_* must not appear in function_exists() without ext/intl.
putenv('PHP_COMPILER_PROFILE=8.4');

if (extension_loaded('intl')) {
    fwrite(STDERR, "SKIP: ext/intl loaded\n");
    exit(0);
}

foreach ([
    'grapheme_str_split',
    'grapheme_strlen',
    'grapheme_substr',
    'grapheme_strpos',
    'grapheme_extract',
    'grapheme_str_contains',
    'grapheme_strimwidth',
] as $name) {
    if (function_exists($name)) {
        fwrite(STDERR, "FAIL: function_exists true for {$name} without ext/intl\n");
        exit(1);
    }
}

echo "ok\n";
