<?php

declare(strict_types=1);

/**
 * Maintainer repro for #16408 — getimagesize() read/parse failure must E_NOTICE before false.
 *
 * php-src: ext/standard/image.c php_getimagesize_from_any()
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$uri = 'data://text/plain,xxx';
$result = @getimagesize($uri);
$last = error_get_last();

var_export($result);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Error reading from data://text/plain,xxx!') ? 'notice_ok' : 'notice_fail';
echo "\n";
