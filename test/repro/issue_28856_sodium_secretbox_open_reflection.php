<?php
/**
 * Repro for #28856 — sodium_crypto_secretbox_open Reflection arity + named args
 * (php-src ext/sodium/libsodium.stub.php).
 */
$r = new ReflectionFunction('sodium_crypto_secretbox_open');
echo 'argc=', $r->getNumberOfParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo ($p->hasType() ? (string) $p->getType() : '<none>'), ' $', $p->getName(), PHP_EOL;
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', PHP_EOL;

$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$c = sodium_crypto_secretbox('hi', $nonce, $key);
$pos = sodium_crypto_secretbox_open($c, $nonce, $key);
$named = sodium_crypto_secretbox_open(ciphertext: $c, nonce: $nonce, key: $key);
echo 'decrypt=', ($pos === 'hi' && $named === 'hi') ? "ok\n" : "BAD\n";
