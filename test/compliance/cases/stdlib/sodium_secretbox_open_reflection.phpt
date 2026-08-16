--TEST--
stdlib sodium_crypto_secretbox_open() Reflection + named args (#28856)
--SKIPIF--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretbox_open')) {
    die('skip sodium secretbox_open missing');
}
?>
--FILE--
<?php
$r = new ReflectionFunction('sodium_crypto_secretbox_open');
echo 'arity=', $r->getNumberOfParameters(), "\n";
$names = [];
foreach ($r->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $names[] = $p->getName() . ':' . $type;
}
echo 'params=', implode(',', $names), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$c = sodium_crypto_secretbox('hi', $nonce, $key);
$plain = sodium_crypto_secretbox_open(ciphertext: $c, nonce: $nonce, key: $key);
echo 'named=', ('hi' === $plain) ? "ok\n" : "BAD\n";
?>
--EXPECT--
arity=3
params=ciphertext:string,nonce:string,key:string
return=string|false
named=ok
