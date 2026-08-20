<?php

declare(strict_types=1);

// Same keypair as #32705 / #32713 public key.
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

$out = '';
var_export(openssl_private_encrypt('hello', $out, $priv));
echo '|';
// Avoid strlen($out): standalone AOT can keep the '' compile-time literal (#15642).
echo md5($out) === 'd41d8cd98f00b204e9800998ecf8427e' ? 'cipher-bad' : 'cipher-ok';
echo '|';
$bad = '';
var_export(openssl_private_encrypt('hello', $bad, 'not-a-key'));
echo "\n";
