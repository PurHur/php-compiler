--TEST--
stdlib hash_hkdf() int $salt coerces to string (#12100, ext/hash/hash_hkdf.c)
--FILE--
<?php
echo bin2hex(hash_hkdf('sha256', 'key', 8, '', 42)), "\n";
try {
    hash_hkdf('sha256', 'key', 8, '', new stdClass());
    echo "no error\n";
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
0166f5eb7ca31b2c
TypeError
