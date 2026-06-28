<?php

declare(strict_types=1);

/**
 * Maintainer repro: stream_supports()/STREAM_SUPPORT_* withheld on Zend 8.2 reference profile (#13238).
 *
 * php-src: ext/standard/streams.c — PHP 8.3+ stream_supports(), gated on stable profile here.
 */

$phantoms = [];
if (\function_exists('stream_supports')) {
    $phantoms[] = 'stream_supports()';
}
foreach (['STREAM_SUPPORT_LOCK', 'STREAM_SUPPORT_SEEK', 'STREAM_SUPPORT_TELL'] as $const) {
    if (\defined($const)) {
        $phantoms[] = $const;
    }
}

if ([] !== $phantoms) {
    echo 'fail: '.implode(', ', $phantoms).' advertised on Zend 8.2 reference profile';
    exit(1);
}

if (!\function_exists('stream_supports_lock')) {
    echo "fail: stream_supports_lock() missing\n";
    exit(1);
}

echo "ok\n";
