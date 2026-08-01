--TEST--
openssl_pkey_derive() bool/int/float/array soft-fail to false (issue #26689, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!function_exists('openssl_pkey_derive')) {
    die('skip openssl_pkey_derive unavailable');
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach ([false, true, 0, 1, 1.5, [], null] as $a) {
    echo gettype($a), ':';
    try {
        var_export(openssl_pkey_derive($a, false));
        echo PHP_EOL;
    } catch (Throwable $e) {
        echo get_class($e), PHP_EOL;
    }
}

try {
    var_export(openssl_pkey_derive([], []));
    echo PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
boolean:false
boolean:false
integer:false
integer:false
double:false
array:false
NULL:false
ValueError:Key array must be of the form array(0 => key, 1 => phrase)
