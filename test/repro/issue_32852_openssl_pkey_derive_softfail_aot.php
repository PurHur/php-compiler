<?php
declare(strict_types=1);

// Residual of #32852 / #26689 — compile-time soft-fail scalars must bake false, not LogicException.
// Unrolled (not foreach): loop use strips heredoc compileTimeString on the PEM local.
$alicePrivate = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEINECSnzz+DNYkIONFEHxZYkuDmKPGSJi6YLFh/S6KcazoAoGCCqGSM49
AwEHoUQDQgAEBIfnBb99kI2pwkDZEJvzby+Kx3QLSW5Q3vk1RgH78kLbLeR/r5E2
FoQhKi3UU7e5wD9eUgQkgPTSVG62qLg43A==
-----END EC PRIVATE KEY-----
PEM;

echo 'boolean:';
var_export(openssl_pkey_derive(false, $alicePrivate));
echo "\nboolean:";
var_export(openssl_pkey_derive(true, $alicePrivate));
echo "\ninteger:";
var_export(openssl_pkey_derive(0, $alicePrivate));
echo "\ninteger:";
var_export(openssl_pkey_derive(1, $alicePrivate));
echo "\ndouble:";
var_export(openssl_pkey_derive(1.5, $alicePrivate));
echo "\nNULL:";
var_export(openssl_pkey_derive(null, $alicePrivate));
echo "\n";
