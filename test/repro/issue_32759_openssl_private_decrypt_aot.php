<?php

declare(strict_types=1);

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

// Fixed PKCS#1 ciphertext for plaintext "hello" under the matching public key
// (openssl_public_encrypt once; RSA decrypt is deterministic for a given blob).
$cipher = "\x7e\x7a\x97\x0a\x78\x61\xe5\x05\xea\x30\x49\x0d\xf9\x6e\x08\x5b\xe3\x7b\x70\xed\x4a\x2c\x1f\x4c\x0f\xc1\x0c\x0c\xaf\x36\xc3\x78\xb0\x28\xf6\xaf\x23\xb6\x8b\x6e\x95\xf3\x7f\xe5\xc1\xb6\xc1\x65\x61\xd8\xaa\x44\x0f\x68\x35\xac\x3b\xf3\x18\x8f\x79\x9b\x65\xef";

$out = '';
var_export(openssl_private_decrypt($cipher, $out, $priv));
echo '|';
echo $out === 'hello' ? 'plain-ok' : 'plain-bad';
echo '|';
$bad = '';
var_export(openssl_private_decrypt('not-cipher', $bad, 'not-a-key'));
echo "\n";
