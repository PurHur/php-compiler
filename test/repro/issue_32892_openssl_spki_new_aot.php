<?php

declare(strict_types=1);

// Same keypair as #32705 / #32757; PEM string form for thin-AOT bake (#32892 leftover of #8690).
$priv = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIBVAIBADANBgkqhkiG9w0BAQEFAASCAT4wggE6AgEAAkEAs/agkMDOJDS7Udfu
b2zoYYZdjXjmjEGVAKQ0jcNsjzx8UizZZdezyq9Cb/a1Z8epPFm0KPXWO/DrfaO/
pJdN0wIDAQABAkEAqAYbsisiDLHjNy35o7U2Xl/6lu0LrGZK/TdTDg0pHa2Tg2bU
sRDsUL7mG+Sg7nXUkGQnMOc6PjHwRlF1v5i6EQIhAO6cRDOKu4OzmpsFpDz8RcAb
fKcHtRGQoqNiHGkjOrd7AiEAwRQwNwDjClD+3IMkLHR/1d2MSRunQ/mYf+SHs51Y
R4kCIA4uXWNO0HwwVXT3Ld6uA5s6RvtKWvmTRgc90oBxJpE3AiAXGnVSf5arS1nT
xRV1BFOvoZ0Bun9fUOSAmTXrti40EQIgd7h1Ch05DM18TUSosFD/valTgZyBNqO5
YQqYKeRM/Yk=
-----END PRIVATE KEY-----
PEM;

// Deterministic RSA PKCS#1 SPKAC for this key + challenge + SHA256 (baked at compile time under AOT).
$expected = 'SPKAC=MIHHMHMwXDANBgkqhkiG9w0BAQEFAANLADBIAkEAs/agkMDOJDS7Udfub2zoYYZdjXjmjEGVAKQ0jcNsjzx8UizZZdezyq9Cb/a1Z8epPFm0KPXWO/DrfaO/pJdN0wIDAQABFhNwaHBjLXNwa2ktY2hhbGxlbmdlMA0GCSqGSIb3DQEBCwUAA0EAk1uDhxd6bg9k0Uqob60Z97vHFbBs4oECeIQisBa5SW4w3kFp+kb0Aav1ZkED1UN4a+4rMNXrpftVCTFNuZkMdQ==';
$payload = 'MIHHMHMwXDANBgkqhkiG9w0BAQEFAANLADBIAkEAs/agkMDOJDS7Udfub2zoYYZdjXjmjEGVAKQ0jcNsjzx8UizZZdezyq9Cb/a1Z8epPFm0KPXWO/DrfaO/pJdN0wIDAQABFhNwaHBjLXNwa2ktY2hhbGxlbmdlMA0GCSqGSIb3DQEBCwUAA0EAk1uDhxd6bg9k0Uqob60Z97vHFbBs4oECeIQisBa5SW4w3kFp+kb0Aav1ZkED1UN4a+4rMNXrpftVCTFNuZkMdQ==';

$s = openssl_spki_new($priv, 'phpc-spki-challenge', OPENSSL_ALGO_SHA256);
echo ($s === $expected) ? 'match-ok' : 'match-bad';
echo '|';
echo openssl_spki_verify($payload) ? 'verify-ok' : 'verify-bad';
echo '|';
var_export(@openssl_spki_new('not-a-key', 'x', OPENSSL_ALGO_SHA256));
echo "\n";
