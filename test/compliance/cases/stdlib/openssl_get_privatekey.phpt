--TEST--
openssl_get_privatekey alias of openssl_pkey_get_private (#20306, ext/openssl/openssl.c)
--FILE--
<?php
foreach (['openssl_pkey_get_private', 'openssl_get_privatekey'] as $f) {
    echo $f, '=', (int) function_exists($f), PHP_EOL;
}
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$pem = '';
if (!openssl_pkey_export($key, $pem) || $pem === '') {
    echo "export-fail\n";
    exit(1);
}
$viaCanon = openssl_pkey_get_private($pem);
$viaAlias = openssl_get_privatekey($pem);
echo 'canon-ok=', (int) (false !== $viaCanon), PHP_EOL;
echo 'alias-ok=', (int) (false !== $viaAlias), PHP_EOL;
$data = 'probe-20306';
$sigCanon = '';
$sigAlias = '';
$okCanon = openssl_sign($data, $sigCanon, $viaCanon, OPENSSL_ALGO_SHA256);
$okAlias = openssl_sign($data, $sigAlias, $viaAlias, OPENSSL_ALGO_SHA256);
echo 'sign-canon=', (int) ($okCanon && $sigCanon !== ''), PHP_EOL;
echo 'sign-alias=', (int) ($okAlias && $sigAlias !== ''), PHP_EOL;
echo 'bad=', var_export(@openssl_get_privatekey('not-a-key'), true), PHP_EOL;
?>
--EXPECT--
openssl_pkey_get_private=1
openssl_get_privatekey=1
canon-ok=1
alias-ok=1
sign-canon=1
sign-alias=1
bad=false
