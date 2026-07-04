<?php

declare(strict_types=1);

/**
 * Maintainer repro: locale_get_default()/Locale must not appear without ext/intl (#16214).
 *
 * php-src: ext/intl/php_intl.c — locale API registered only with loaded intl module.
 */

$phantoms = [];
if (\function_exists('locale_get_default')) {
    $phantoms[] = 'locale_get_default';
}
if (\function_exists('locale_set_default')) {
    $phantoms[] = 'locale_set_default';
}
if (\class_exists('Locale', false)) {
    $phantoms[] = 'Locale';
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
