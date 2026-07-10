<?php

declare(strict_types=1);

// Issue #17608 — forward 8.4: function_exists/is_callable for core grapheme cluster without ext/intl.
putenv('PHP_COMPILER_PROFILE=8.4');

$core = [
    'grapheme_strlen',
    'grapheme_str_split',
];
foreach ($core as $name) {
    if (!function_exists($name)) {
        echo "fail: function_exists false for {$name}\n";
        exit(1);
    }
    if (!is_callable($name)) {
        echo "fail: is_callable false for {$name}\n";
        exit(1);
    }
}

$s = "a\xCC\x81b";
if (2 !== grapheme_strlen($s)) {
    echo "fail: grapheme_strlen result\n";
    exit(1);
}
if (2 !== count(grapheme_str_split($s))) {
    echo "fail: grapheme_str_split result\n";
    exit(1);
}

foreach (['grapheme_stripos', 'grapheme_stristr', 'grapheme_strrpos'] as $name) {
    if (function_exists($name)) {
        echo "fail: {$name} must stay absent without full ext/intl\n";
        exit(1);
    }
}

echo "ok\n";
