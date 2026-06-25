<?php

declare(strict_types=1);

/**
 * Maintainer repro: stream_supports() phantom on PHP 8.2 reference profile (#11819).
 *
 * php-src: ext/standard/streams.c — stream_supports() is PHP 8.4+.
 */

if (function_exists('stream_supports')) {
    echo "fail: stream_supports advertised without php-src ext\n";
    exit(1);
}

if (defined('STREAM_SUPPORT_LOCK')) {
    echo "fail: STREAM_SUPPORT_LOCK defined on reference profile\n";
    exit(1);
}

if (defined('STREAM_SUPPORT_SEEK')) {
    echo "fail: STREAM_SUPPORT_SEEK defined on reference profile\n";
    exit(1);
}

if (defined('STREAM_SUPPORT_TELL')) {
    echo "fail: STREAM_SUPPORT_TELL defined on reference profile\n";
    exit(1);
}

echo "ok\n";
