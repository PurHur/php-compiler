--TEST--
sodium_crypto_* named arguments + Reflection (VM, issue #24490 / #28753 / #28856)
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
    'sodium_crypto_secretbox_open' => 'ciphertext,nonce,key',
    'sodium_crypto_box' => 'message,nonce,key_pair',
    'sodium_crypto_sign' => 'message,secret_key',
    'sodium_crypto_sign_detached' => 'message,secret_key',
    'sodium_crypto_sign_verify_detached' => 'signature,message,public_key',
    'sodium_crypto_box_seal' => 'message,public_key',
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
$kp = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($kp);
$sig = sodium_crypto_sign_detached(message: 'm', secret_key: $sk);
echo (strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES) ? "sign_detached_named_ok\n" : "sign_detached_named_BAD\n";
$sbKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sbNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$sbCipher = sodium_crypto_secretbox('hi', $sbNonce, $sbKey);
$sbPlain = sodium_crypto_secretbox_open(ciphertext: $sbCipher, nonce: $sbNonce, key: $sbKey);
echo ('hi' === $sbPlain) ? "secretbox_open_named_ok\n" : "secretbox_open_named_BAD\n";
--EXPECT--
sodium_crypto_generichash=message,key,length ok
sodium_crypto_secretbox=message,nonce,key ok
sodium_crypto_secretbox_open=ciphertext,nonce,key ok
sodium_crypto_box=message,nonce,key_pair ok
sodium_crypto_sign=message,secret_key ok
sodium_crypto_sign_detached=message,secret_key ok
sodium_crypto_sign_verify_detached=signature,message,public_key ok
sodium_crypto_box_seal=message,public_key ok
sodium_crypto_pwhash_str=password,opslimit,memlimit ok
named_match
wrong_name_rejected
sign_detached_named_ok
secretbox_open_named_ok
