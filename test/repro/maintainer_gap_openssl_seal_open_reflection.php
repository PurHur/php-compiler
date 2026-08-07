<?php
/**
 * Issue #28754 — openssl_seal/openssl_open Reflection names + return + named args.
 * php-src: ext/openssl/openssl.stub.php
 */
foreach (['openssl_seal', 'openssl_open'] as $f) {
    $r = new ReflectionFunction($f);
    echo '== ', $f, ' ==', PHP_EOL;
    echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
    foreach ($r->getParameters() as $p) {
        echo ($p->isPassedByReference() ? '&' : ''), $p->getName(),
            ':', ($p->hasType() ? (string) $p->getType() : 'untyped'),
            ($p->isOptional() ? ' opt' : ''), PHP_EOL;
    }
}
$pk = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$pub = openssl_pkey_get_public(openssl_pkey_get_details($pk)['key']);
$sealed = null;
$ekeys = null;
$iv = null;
$len = openssl_seal(data: 'hi', sealed_data: $sealed, encrypted_keys: $ekeys, public_key: [$pub], cipher_algo: 'AES-128-CBC', iv: $iv);
echo 'named_seal=', is_int($len) && $len > 0 ? 'ok' : var_export($len, true), PHP_EOL;
