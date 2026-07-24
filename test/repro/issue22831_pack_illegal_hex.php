<?php
/**
 * Repro #22831 — pack() H/h illegal hex digit must emit Zend E_WARNING.
 * php-src: ext/standard/pack.c — php_pack() hex path.
 *
 * Error handler stays untyped so AOT nested JIT can compile the callback
 * (typed int64 errno is not supported for JIT/AOT).
 */
function capture($errno, $message)
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('capture');
echo 'H=', bin2hex(pack('H', 'x')), "\n";
echo 'H*=', bin2hex(pack('H*', '0g1')), "\n";
echo 'h*=', bin2hex(pack('h*', 'gx')), "\n";
