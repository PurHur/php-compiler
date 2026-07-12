<?php

declare(strict_types=1);

// Issue #17694 — forward 8.4 profile: grapheme cluster must stay absent without ext/intl.
putenv('PHP_COMPILER_PROFILE=8.4');

if (extension_loaded('intl')) {
    fwrite(STDERR, "SKIP: ext/intl loaded\n");
    exit(0);
}

$core = [
    'grapheme_strlen',
    'grapheme_str_split',
    'grapheme_str_contains',
    'grapheme_strimwidth',
];
foreach ($core as $name) {
    if (function_exists($name) || is_callable($name)) {
        echo "fail: {$name} visible without ext/intl\n";
        exit(1);
    }
}

echo "ok\n";
