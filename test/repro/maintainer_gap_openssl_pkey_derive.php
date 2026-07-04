<?php
declare(strict_types=1);

if (!function_exists('openssl_pkey_derive')) {
    fwrite(STDERR, "openssl_pkey_derive not registered\n");
    exit(1);
}

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
if (!\is_string($shared) || '' === $shared) {
    echo "fail: derive returned empty\n";
    exit(1);
}

if (32 !== \strlen($shared)) {
    echo 'fail: unexpected length '.\strlen($shared)."\n";
    exit(1);
}

if ('a89ceecb80ea0e5b66a50bb93ca3bb8f9a490c67897cc56734d28061a086d6b5' !== bin2hex($shared)) {
    echo 'fail: unexpected secret '.\bin2hex($shared)."\n";
    exit(1);
}

$nullPublic = openssl_pkey_derive(null, $alicePrivate);
if (false !== $nullPublic) {
    echo 'fail: null public_key expected false, got '.var_export($nullPublic, true)."\n";
    exit(1);
}

echo "ok\n";
