<?php
declare(strict_types=1);
/**
 * Mini-spine: predecessor `defined()` Literals then require nested bool array.
 * Compiled with SourceBundler skipped + PHP_COMPILER_INCLUDE_SCOPE_REMAP=0 (#29111).
 */
defined('PHP_VERSION');
defined('PHP_OS');
defined('DIRECTORY_SEPARATOR');
defined('PHP_EOL');
defined('PHP_INT_MAX');
defined('PHP_INT_MIN');
defined('PHP_FLOAT_MAX');
$tz = require __DIR__.'/data.php';
echo count($tz), "\n";
