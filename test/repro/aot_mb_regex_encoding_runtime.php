<?php

declare(strict_types=1);

/**
 * AOT: mb_regex_encoding() opaque runtime string setter (#35284).
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_regex_encoding)
 */
function enc_utf8(): string
{
    return 'UTF-8';
}

function enc_ascii(): string
{
    return 'ASCII';
}

var_export(mb_regex_encoding(enc_utf8()));
echo "\n";
var_export(mb_regex_encoding());
echo "\n";
var_export(mb_regex_encoding(enc_ascii()));
echo "\n";
var_export(mb_regex_encoding());
echo "\n";
// Compile-time literal path must keep working.
var_export(mb_regex_encoding('UTF-8'));
echo "\n";
var_export(mb_regex_encoding());
echo "\n";
