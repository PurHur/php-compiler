<?php

declare(strict_types=1);

/**
 * Maintainer repro: grapheme_stripos()/grapheme_stristr()/grapheme_strrpos() must not
 * appear in function_exists() without ext/intl (#11815, ext/intl/grapheme).
 */

foreach (['grapheme_stripos', 'grapheme_stristr', 'grapheme_strrpos'] as $fn) {
    if (\function_exists($fn)) {
        echo "fail: {$fn}: function_exists true\n";
        exit(1);
    }
    echo "ok {$fn}: absent\n";
}

if (\extension_loaded('intl')) {
    echo "fail: extension_loaded(intl)=true without full ext/intl\n";
    exit(1);
}

echo "ok\n";
