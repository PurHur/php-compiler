--TEST--
sodium_crypto_* named arguments + Reflection (VM, issue #24490)
--SKIPIF--
<?php
if (!extension_loaded('sodium') && !function_exists('sodium_crypto_generichash')) {
    echo "skip sodium not available\n";
}
?>
--FILE--
<?php
$funcs = [
    'sodium_crypto_generichash' => 'message,key,length',
    'sodium_crypto_secretbox' => 'message,nonce,key',
    'sodium_crypto_box' => 'message,nonce,key_pair',
    'sodium_crypto_sign' => 'message,secret_key',
    'sodium_crypto_pwhash_str' => 'password,opslimit,memlimit',
];
foreach ($funcs as $f => $expect) {
    $r = new ReflectionFunction($f);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, '=', implode(',', $names), ($expect === implode(',', $names) ? ' ok' : ' BAD'), PHP_EOL;
}
$key = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
$named = bin2hex(sodium_crypto_generichash(message: 'hi', key: $key));
$pos = bin2hex(sodium_crypto_generichash('hi', $key));
echo ($named === $pos) ? "named_match\n" : "named_mismatch\n";
try {
    sodium_crypto_generichash(msg: 'hi');
    echo "wrong_name_accepted\n";
} catch (Error $e) {
    echo "wrong_name_rejected\n";
}
--EXPECT--
sodium_crypto_generichash=message,key,length ok
sodium_crypto_secretbox=message,nonce,key ok
sodium_crypto_box=message,nonce,key_pair ok
sodium_crypto_sign=message,secret_key ok
sodium_crypto_pwhash_str=password,opslimit,memlimit ok
named_match
wrong_name_rejected
