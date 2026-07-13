--TEST--
openssl_dh_compute_key() — DH shared secret from raw peer public bytes (issue #6596, ext/openssl/openssl_backend_v3.c)
--SKIPIF--
<?php
if (!function_exists('openssl_dh_compute_key')) {
    die('skip openssl_dh_compute_key unavailable');
}
?>
--FILE--
<?php
declare(strict_types=1);

$private = openssl_pkey_get_private(<<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGcAgEAMFMGCSqGSIb3DQEDATBGAkEAsMkZoMPnt4ETce/ra8LjYmpMJmylxjRI
29bzbeTDuHfqxXpu9jdXNxFhOyELjDIZycLjch2Yj7ChzhX16ztiDwIBAgRCAkBM
H7HxDbBqSTOMS4B0ygdVlmgfbQrKUqLIi+448nOWl4Ge3TSSXqAnO5mjO6GdWQA4
5UGx9/y+qxJUYI9xZh5h
-----END PRIVATE KEY-----
PEM);

$peerPub = hex2bin(
    '8ee141b06e8da9cb882f89c093d9e2b6369e845604e4c7a91de9a727e2319f66c8bfcd53e5072da7eaee670612d12391ede4b15952a8b61e400ff0b2719dcefe'
);

echo bin2hex(openssl_dh_compute_key($peerPub, $private)), "\n";
var_export(openssl_dh_compute_key($peerPub, $private) !== false);
echo "\n";
?>
--EXPECT--
08cd9637c937aadf4f467ca6ca7d4ded0ab62d156569c87567d00c66921c35a57ec3ab1365a162ce73e64744a86dda9627b9400a6987edffff35b326b7cfb7
true
