<?php

declare(strict_types=1);

$expected = [
    'CRYPT_STD_DES' => 1,
    'CRYPT_EXT_DES' => 1,
    'CRYPT_MD5' => 1,
    'CRYPT_BLOWFISH' => 1,
    'CRYPT_SHA256' => 1,
    'CRYPT_SHA512' => 1,
];

foreach ($expected as $name => $want) {
    if (!\defined($name)) {
        echo "fail: undefined constant {$name}\n";
        exit(1);
    }
    $got = \constant($name);
    if ($got !== $want) {
        echo "fail: {$name}={$got} expected {$want}\n";
        exit(1);
    }
}

echo "ok\n";
