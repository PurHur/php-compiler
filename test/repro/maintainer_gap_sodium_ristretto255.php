<?php
declare(strict_types=1);

/**
 * Repro for #20084 — sodium ristretto255 core + scalarmult_* (VM).
 */
if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium unavailable\n");
    exit(0);
}

$fns = [
    'sodium_crypto_core_ristretto255_is_valid_point',
    'sodium_crypto_core_ristretto255_random',
    'sodium_crypto_core_ristretto255_from_hash',
    'sodium_crypto_core_ristretto255_add',
    'sodium_crypto_core_ristretto255_sub',
    'sodium_crypto_core_ristretto255_scalar_random',
    'sodium_crypto_core_ristretto255_scalar_invert',
    'sodium_crypto_core_ristretto255_scalar_negate',
    'sodium_crypto_core_ristretto255_scalar_complement',
    'sodium_crypto_core_ristretto255_scalar_add',
    'sodium_crypto_core_ristretto255_scalar_sub',
    'sodium_crypto_core_ristretto255_scalar_mul',
    'sodium_crypto_core_ristretto255_scalar_reduce',
    'sodium_crypto_scalarmult_ristretto255',
    'sodium_crypto_scalarmult_ristretto255_base',
];
foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "fail: {$fn}() not registered\n");
        exit(1);
    }
    echo $fn, "=Y\n";
}

$consts = [
    'SODIUM_CRYPTO_CORE_RISTRETTO255_BYTES' => 32,
    'SODIUM_CRYPTO_CORE_RISTRETTO255_HASHBYTES' => 64,
    'SODIUM_CRYPTO_CORE_RISTRETTO255_SCALARBYTES' => 32,
    'SODIUM_CRYPTO_CORE_RISTRETTO255_NONREDUCEDSCALARBYTES' => 64,
    'SODIUM_CRYPTO_SCALARMULT_RISTRETTO255_BYTES' => 32,
    'SODIUM_CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES' => 32,
];
foreach ($consts as $name => $want) {
    if (!defined($name) || constant($name) !== $want) {
        fwrite(STDERR, "fail: {$name}\n");
        exit(1);
    }
}
echo "constants=OK\n";

$p = sodium_crypto_core_ristretto255_random();
echo 'random_valid=', sodium_crypto_core_ristretto255_is_valid_point($p) ? 'OK' : 'FAIL', "\n";

$s = sodium_crypto_core_ristretto255_scalar_random();
$q = sodium_crypto_scalarmult_ristretto255_base($s);
echo 'scalarmult_base_valid=', sodium_crypto_core_ristretto255_is_valid_point($q) ? 'OK' : 'FAIL', "\n";

$h = random_bytes(SODIUM_CRYPTO_CORE_RISTRETTO255_HASHBYTES);
$from = sodium_crypto_core_ristretto255_from_hash($h);
$a = sodium_crypto_core_ristretto255_add($p, $from);
$b = sodium_crypto_core_ristretto255_sub($a, $from);
echo 'add_sub_roundtrip=', ($b === $p) ? 'OK' : 'FAIL', "\n";

$x = sodium_crypto_core_ristretto255_scalar_random();
$y = sodium_crypto_core_ristretto255_scalar_random();
$sum = sodium_crypto_core_ristretto255_scalar_add($x, $y);
$back = sodium_crypto_core_ristretto255_scalar_sub($sum, $y);
echo 'scalar_add_sub=', ($back === $x) ? 'OK' : 'FAIL', "\n";

try {
    sodium_crypto_scalarmult_ristretto255_base(str_repeat("\0", SODIUM_CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES));
    echo "zero_scalar_fail\n";
} catch (SodiumException $e) {
    echo "zero_scalar_ok\n";
}
