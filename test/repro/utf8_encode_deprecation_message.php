<?php
/**
 * #29249 — utf8_encode()/utf8_decode() E_DEPRECATED text matches Zend (since 8.2 + php.net hint).
 * php-src: ext/standard/utf8.c
 * AOT-safe: uses error_get_last() (no set_error_handler closures).
 */
error_reporting(E_ALL);

$enc = @utf8_encode("\xE9");
$e = error_get_last();
echo ($e['message'] ?? '(missing encode deprecation)'), "\n";

$dec = @utf8_decode("\xC3\xA9");
$e = error_get_last();
echo ($e['message'] ?? '(missing decode deprecation)'), "\n";

echo bin2hex($enc), "\n";
echo bin2hex($dec), "\n";
