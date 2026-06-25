<?php

declare(strict_types=1);

/**
 * Maintainer repro: extension_loaded('intl') must be false without full ext/intl (#11472).
 *
 * php-src: ext/standard/info.c — extension_loaded only true for linked modules.
 */

if (extension_loaded('intl')) {
    echo 'FAIL extension_loaded(intl)=true';
    if (\function_exists('grapheme_strlen')) {
        echo ' grapheme_strlen exists=true';
    }
    exit(1);
}

if (\in_array('intl', get_loaded_extensions(), true)) {
    echo 'FAIL intl in get_loaded_extensions()';
    exit(1);
}

if (false !== get_extension_funcs('intl')) {
    echo 'FAIL get_extension_funcs(intl) not false';
    exit(1);
}

echo "ok\n";
