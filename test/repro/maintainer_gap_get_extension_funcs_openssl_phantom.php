<?php

declare(strict_types=1);

/**
 * Maintainer repro: get_extension_funcs('standard') must not list openssl_* when ext/openssl unloaded (#15045).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_extension_funcs)
 */

if (extension_loaded('openssl')) {
    echo 'SKIP openssl loaded';
    exit(0);
}

$funcs = get_extension_funcs('standard');
if (!is_array($funcs)) {
    echo 'FAIL get_extension_funcs(standard) not array';
    exit(1);
}

if (in_array('openssl_encrypt', $funcs, true)) {
    echo 'FAIL openssl_encrypt listed under standard';
    exit(1);
}

$head = array_slice($funcs, 0, 3);
if (in_array('openssl_encrypt', $head, true)
    || in_array('openssl_decrypt', $head, true)
    || in_array('openssl_sign', $head, true)) {
    echo 'FAIL openssl_* at head of standard funcs';
    exit(1);
}

if (false !== get_extension_funcs('openssl')) {
    echo 'FAIL get_extension_funcs(openssl) should be false';
    exit(1);
}

echo "ok\n";
