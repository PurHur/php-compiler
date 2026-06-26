<?php

declare(strict_types=1);

/**
 * Maintainer repro: grapheme_str_split() must not appear in function_exists() without ext/intl (#11825).
 *
 * php-src: ext/intl/php_intl.c — grapheme helpers registered only with loaded intl module.
 */

$phantoms = [];
foreach ([
    'grapheme_str_split',
    'grapheme_extract',
    'grapheme_substr',
    'grapheme_strpos',
    'grapheme_stripos',
    'grapheme_stristr',
    'grapheme_strrpos',
    'grapheme_strlen',
    'intl_get_error_code',
] as $fn) {
    if (\function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: '.implode(', ', $phantoms).' advertised without intl';
    exit(1);
}

if (\extension_loaded('intl')) {
    echo 'fail: extension_loaded(intl)=true without full ext/intl';
    exit(1);
}

echo "ok: absent\n";
