--TEST--
stdlib CRYPT_* constants — all bitmask 1 (issue #14088, ext/standard/crypt.c)
--FILE--
<?php
$expected = [
    'CRYPT_STD_DES' => 1,
    'CRYPT_EXT_DES' => 1,
    'CRYPT_MD5' => 1,
    'CRYPT_BLOWFISH' => 1,
    'CRYPT_SHA256' => 1,
    'CRYPT_SHA512' => 1,
];
foreach ($expected as $name => $want) {
    if (!defined($name)) {
        echo "undef:{$name}\n";
        continue;
    }
    echo constant($name) === $want ? "{$name}_ok\n" : "{$name}_bad\n";
}
--EXPECT--
CRYPT_STD_DES_ok
CRYPT_EXT_DES_ok
CRYPT_MD5_ok
CRYPT_BLOWFISH_ok
CRYPT_SHA256_ok
CRYPT_SHA512_ok
