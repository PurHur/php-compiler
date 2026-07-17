--TEST--
openssl_pkey_get_public/get_details/alias (#20240, ext/openssl/openssl.c)
--FILE--
<?php
foreach (['openssl_pkey_get_private', 'openssl_pkey_get_public', 'openssl_get_publickey', 'openssl_pkey_get_details'] as $f) {
    echo $f, '=', (int) function_exists($f), PHP_EOL;
}
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$details = openssl_pkey_get_details($key);
if (!is_array($details) || !isset($details['bits'], $details['key'], $details['type'])) {
    echo "details-fail\n";
    exit(1);
}
echo 'bits=', (int) $details['bits'], ' type=', (int) $details['type'], PHP_EOL;
echo 'has-rsa=', (int) isset($details['rsa']['n'], $details['rsa']['e'], $details['rsa']['d']), PHP_EOL;
$pub = openssl_pkey_get_public($details['key']);
if (false === $pub) {
    echo "pub-fail\n";
    exit(1);
}
$alias = openssl_get_publickey($details['key']);
echo 'alias-ok=', (int) (false !== $alias), PHP_EOL;
$pubDetails = openssl_pkey_get_details($pub);
echo 'pub-rsa=', implode(',', array_keys($pubDetails['rsa'] ?? [])), PHP_EOL;
$privPem = '';
openssl_pkey_export($key, $privPem);
$fromPriv = @openssl_pkey_get_public($privPem);
echo 'priv-pem=', var_export($fromPriv, true), PHP_EOL;
?>
--EXPECT--
openssl_pkey_get_private=1
openssl_pkey_get_public=1
openssl_get_publickey=1
openssl_pkey_get_details=1
bits=512 type=0
has-rsa=1
alias-ok=1
pub-rsa=n,e
priv-pem=false
