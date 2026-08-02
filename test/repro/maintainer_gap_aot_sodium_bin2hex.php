<?php
declare(strict_types=1);

/**
 * Repro for #26871 — sodium_bin2hex() AOT must match Zend/VM/JIT (6162 for "ab").
 * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_bin2hex)
 */
$s = 'a' . 'b';
echo sodium_bin2hex($s), "\n";
