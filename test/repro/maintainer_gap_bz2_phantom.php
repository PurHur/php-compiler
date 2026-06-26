<?php

declare(strict_types=1);

/**
 * Maintainer repro: extension_loaded('bz2') / bzcompress() phantom without libbz2 (#11840).
 *
 * php-src: ext/bz2/bz2.c — module registered only when libbz2 is linked.
 * Run on a profile without libbz2 (Docker reference); hosts with libbz2 expect loaded=true.
 */

if (\extension_loaded('bz2') && !\function_exists('bzcompress')) {
    echo "fail: extension_loaded(bz2) true but bzcompress missing\n";
    exit(1);
}

if (!\extension_loaded('bz2') && (\function_exists('bzcompress') || \function_exists('bzdecompress'))) {
    echo "fail: bz2 functions advertised without extension_loaded(bz2)\n";
    exit(1);
}

if (\extension_loaded('bz2') !== \function_exists('bzcompress')) {
    echo "fail: extension_loaded(bz2) and function_exists(bzcompress) disagree\n";
    exit(1);
}

if (\extension_loaded('bz2') && !\in_array('bz2', \get_loaded_extensions(), true)) {
    echo "fail: bz2 not in get_loaded_extensions()\n";
    exit(1);
}

echo "ok\n";
