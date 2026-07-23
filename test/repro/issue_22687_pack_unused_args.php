<?php
/**
 * Issue #22687 — pack() leftover value args must E_WARNING like Zend pack.c.
 *
 * php-src: ext/standard/pack.c — php_error_docref "%d arguments unused"
 */
function pack_unused_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('pack_unused_warn_capture');
var_export(pack('a', 1, 2));
echo "\n";
