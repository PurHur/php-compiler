<?php

declare(strict_types=1);

/**
 * Issue #18405 — getimagesize(php://memory) must E_NOTICE, not E_WARNING ENOENT.
 *
 * php-src: ext/standard/image.c — php_getimagesize_from_any
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$result = @getimagesize('php://memory');
$last = error_get_last();

var_export($result);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Error reading from php://memory!') ? 'notice_ok' : 'notice_fail';
echo "\n";
