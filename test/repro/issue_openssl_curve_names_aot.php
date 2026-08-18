<?php
/**
 * openssl_get_curve_names() JIT/AOT (#32364, #6560 leftover).
 * php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_get_curve_names) / EC_get_builtin_curves.
 */
$curves = openssl_get_curve_names();
echo 'curves:'.count($curves)."\n";
echo in_array('prime256v1', $curves, true) ? "p256:1\n" : "p256:0\n";
echo in_array('secp384r1', $curves, true) ? "p384:1\n" : "p384:0\n";
