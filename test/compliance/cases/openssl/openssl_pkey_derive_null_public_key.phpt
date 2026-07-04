--TEST--
openssl_pkey_derive() null public_key returns false without TypeError (issue #15768, ext/openssl/pkey.c)
--SKIPIF--
<?php
if (!function_exists('openssl_pkey_derive')) {
    die('skip openssl_pkey_derive unavailable');
}
?>
--FILE--
<?php
declare(strict_types=1);

$private = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEINECSnzz+DNYkIONFEHxZYkuDmKPGSJi6YLFh/S6KcazoAoGCCqGSM49
AwEHoUQDQgAEBIfnBb99kI2pwkDZEJvzby+Kx3QLSW5Q3vk1RgH78kLbLeR/r5E2
FoQhKi3UU7e5wD9eUgQkgPTSVG62qLg43A==
-----END EC PRIVATE KEY-----
PEM;

var_export(openssl_pkey_derive(null, $private));
?>
--EXPECT--
false
