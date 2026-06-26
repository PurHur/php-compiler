<?php

declare(strict_types=1);

/**
 * Maintainer repro: intl OOP classes must not phantom class_exists() without ext/intl (#12115, #11756).
 *
 * php-src: ext/intl/php_intl.c — module classes registered only when intl is loaded.
 */

if (\extension_loaded('intl')) {
    echo "skip: intl loaded\n";
    exit(0);
}

$phantoms = [];
foreach (['IntlDateFormatter', 'Collator', 'IntlException'] as $class) {
    if (\class_exists($class, false)) {
        $phantoms[] = $class;
    }
}

if ([] !== $phantoms) {
    echo 'fail: '.implode(', ', $phantoms).' class_exists without intl';
    exit(1);
}

echo "ok\n";
