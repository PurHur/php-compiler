<?php

declare(strict_types=1);

/**
 * Issue #11676 — extension_loaded('zip') phantom probe (ext/zip/php_zip.c).
 */

$loaded = extension_loaded('zip') ? 'yes' : 'no';
$zipArchive = class_exists('ZipArchive') ? 'yes' : 'no';

$probe = 'absent';
if ($zipArchive === 'yes') {
    try {
        new ZipArchive();
        $probe = 'ok';
    } catch (Throwable $e) {
        $probe = $e::class;
    }
}

echo "loaded={$loaded} ZipArchive={$zipArchive} probe={$probe}\n";

if ($loaded !== 'no' || $zipArchive !== 'no') {
    echo "fail: zip probe must be absent until ext/zip parity (#3337)\n";
    exit(1);
}

echo "ok\n";
