<?php

declare(strict_types=1);

/**
 * Maintainer repro: extension_loaded('zip') must be false without ext/zip on reference profile (#18137).
 *
 * php-src: ext/standard/info.c — extension_loaded only true for linked modules.
 */

if (extension_loaded('zip')) {
    echo 'FAIL extension_loaded(zip)=true';
    exit(1);
}

if (\in_array('zip', get_loaded_extensions(), true)) {
    echo 'FAIL zip in get_loaded_extensions()';
    exit(1);
}

if (class_exists('ZipArchive', false)) {
    echo 'FAIL ZipArchive class advertised';
    exit(1);
}

if (\function_exists('zip_open')) {
    echo 'FAIL zip_open advertised';
    exit(1);
}

echo "ok\n";
