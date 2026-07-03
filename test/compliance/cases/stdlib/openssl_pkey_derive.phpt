--TEST--
stdlib openssl_pkey_derive() ECDH shared secret (#15428, ext/openssl/pkey.c)
--SKIPIF--
<?php
if (!function_exists('openssl_pkey_derive')) {
    echo 'skip: openssl_pkey_derive not registered';
    exit(0);
}
if (!\PHPCompiler\ext\openssl\VmOpensslPkeyDeriveNative::available()) {
    echo 'skip: libcrypto FFI unavailable';
    exit(0);
}
?>
--FILE--
<?php
$alicePrivate = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEINECSnzz+DNYkIONFEHxZYkuDmKPGSJi6YLFh/S6KcazoAoGCCqGSM49
AwEHoUQDQgAEBIfnBb99kI2pwkDZEJvzby+Kx3QLSW5Q3vk1RgH78kLbLeR/r5E2
FoQhKi3UU7e5wD9eUgQkgPTSVG62qLg43A==
-----END EC PRIVATE KEY-----
PEM;
$bobPublic = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEycQC9/n88uWz5TfRBpVbiWAfOn5A
TiLVZcDrF6mzgco+dPRy/rk/Bu6oqH3EU0RTqD8y4tlWdRl2u2GCW37RBg==
-----END PUBLIC KEY-----
PEM;
$shared = openssl_pkey_derive($bobPublic, $alicePrivate);
echo strlen($shared), "\n";
echo bin2hex($shared), "\n";
?>
--EXPECT--
32
a89ceecb80ea0e5b66a50bb93ca3bb8f9a490c67897cc56734d28061a086d6b5
