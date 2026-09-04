<?php

declare(strict_types=1);

/**
 * Discarded function_exists / extension_loaded must not change observable output (#36386).
 *
 * Live extension_loaded(positive) is not Zend-parity under AOT yet (empty module
 * table); assert function_exists + shared-false extension_loaded only.
 *
 * php-src: Zend/zend_builtin_functions.c (function_exists),
 * ext/standard/info.c (extension_loaded)
 */

function work(string $fn, string $ext): string
{
    function_exists($fn);
    extension_loaded($ext);

    $a = function_exists('strlen') ? '1' : '0';
    $b = function_exists('no_such_fn_zz') ? '1' : '0';
    $c = extension_loaded('no_such_ext_zz') ? '1' : '0';

    return $a.$b.$c;
}

echo work('strlen', 'standard'), "\n";
echo work('array_map', 'core'), "\n";
