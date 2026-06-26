<?php

declare(strict_types=1);

/**
 * Maintainer repro: CurlHandle OOP classes must not phantom class_exists() without ext/curl (#12117).
 *
 * php-src: ext/curl/interface.c — module classes registered only when curl is loaded.
 */

if (\extension_loaded('curl')) {
    echo "skip: curl loaded\n";
    exit(0);
}

$phantoms = [];
foreach (['CurlHandle', 'CurlMultiHandle', 'CurlShareHandle'] as $class) {
    if (\class_exists($class, false)) {
        $phantoms[] = $class;
    }
}

if ([] !== $phantoms) {
    echo 'phantom_bad: '.implode(', ', $phantoms);
    exit(1);
}

if (!\extension_loaded('curl')) {
    echo "ok\n";
    exit(0);
}

echo "fail: unexpected curl loaded\n";
exit(1);
