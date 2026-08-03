<?php
/** Repro for #24490 — sodium_crypto_* Reflection + named args. */
$funcs = [
    'sodium_crypto_generichash',
    'sodium_crypto_secretbox',
    'sodium_crypto_box',
    'sodium_crypto_sign',
    'sodium_crypto_pwhash_str',
];
foreach ($funcs as $f) {
    $r = new ReflectionFunction($f);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ' count=', $r->getNumberOfParameters(), ' names=', implode(',', $names), "\n";
}
$key = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
echo 'named=', bin2hex(sodium_crypto_generichash(message: 'hi', key: $key)), "\n";
