<?php

declare(strict_types=1);

/**
 * AOT: ZipArchive::CREATE under PHP_COMPILER_ENABLE_ZIP (#34412 leftover of #28110).
 *
 * php-src: ext/zip/php_zip.c REGISTER_ZIPARCHIVE_CLASS_CONST_*
 */
$z = new ZipArchive();
echo get_class($z), PHP_EOL;
echo ZipArchive::CREATE, PHP_EOL;
echo ZipArchive::OVERWRITE, PHP_EOL;
