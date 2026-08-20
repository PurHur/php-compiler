<?php
declare(strict_types=1);

// Fixed ECDH P-256 PEMs from test/compliance/cases/stdlib/openssl_pkey_derive.phpt (#15428 / #32852).
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
echo is_string($shared) ? bin2hex($shared) : 'false';
echo '|';
var_export(openssl_pkey_derive('not-a-key', $alicePrivate));
echo "\n";
