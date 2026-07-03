<?php
declare(strict_types=1);

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium unavailable\n");
    exit(0);
}

if (!function_exists('sodium_memcmp')) {
    fwrite(STDERR, "fail: sodium_memcmp() not registered\n");
    exit(1);
}

$eq = sodium_memcmp('abc', 'abc');
$ne = sodium_memcmp('abc', 'abd');
$nulEq = sodium_memcmp("a\0b", "a\0b");
$nulNe = sodium_memcmp("a\0b", "a\0c");

echo $eq === 0 ? "eq_ok\n" : "eq_fail\n";
echo $ne !== 0 ? "ne_ok\n" : "ne_fail\n";
echo $nulEq === 0 ? "nul_eq_ok\n" : "nul_eq_fail\n";
echo $nulNe !== 0 ? "nul_ne_ok\n" : "nul_ne_fail\n";

try {
    sodium_memcmp('a', 'ab');
    echo "len_fail\n";
} catch (\SodiumException $e) {
    echo "len_ok\n";
}
