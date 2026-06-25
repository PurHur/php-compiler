<?php

declare(strict_types=1);

/**
 * Issue #11569 — hex2bin() invalid input must prefix E_WARNING with hex2bin():
 *
 * php-src: ext/standard/string.c — php_hex2bin
 */
$msg = '';
set_error_handler(static function (int $severity, string $message) use (&$msg): bool {
    $msg = $message;

    return true;
});
hex2bin('zz');
echo 'has_prefix=', str_starts_with($msg, 'hex2bin():') ? 'yes' : 'no', "\n";
