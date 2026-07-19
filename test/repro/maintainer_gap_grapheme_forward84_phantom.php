<?php

declare(strict_types=1);

// Issue #17694 — forward 8.4 profile must not advertise grapheme cluster without ext/intl.
putenv('PHP_COMPILER_PROFILE=8.4');

if (extension_loaded('intl')) {
    fwrite(STDERR, "SKIP: ext/intl loaded\n");
    exit(0);
}

foreach ([
    'grapheme_strlen',
    'grapheme_str_split',
    'grapheme_str_contains',
    'grapheme_strimwidth',
] as $name) {
    if (function_exists($name)) {
        echo "fail: function_exists true for {$name}\n";
        exit(1);
    }
    if (is_callable($name)) {
        echo "fail: is_callable true for {$name}\n";
        exit(1);
    }
}

foreach (['grapheme_stripos', 'grapheme_stristr', 'grapheme_strrpos', 'grapheme_strripos'] as $name) {
    if (function_exists($name)) {
        echo "fail: {$name} must stay absent without full ext/intl\n";
        exit(1);
    }
}

echo "ok\n";
