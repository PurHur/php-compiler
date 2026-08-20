<?php

declare(strict_types=1);

// Same keypair as #32757. Sealed blob produced via VmOpensslSealNative::seal('hello-seal', …).
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

$sealed = "\x8c\x79\x2a\x27\xb4\x1f\xda\x55\x9b\x19\xfa\x4d\xab\xa9\x2f\x4d";
$ekey = "\x6a\x78\x34\x7f\xb5\x66\x9a\x8c\xba\xd3\x12\x1c\xb8\x3c\x3b\x8f\x84\xcc\xf7\x1b\x89\x85\x37\x63\x51\x8b\xea\xca\xe5\x7e\xe1\xdf\xd1\x14\x64\x0b\x9d\xc1\x41\x13\xf3\x7b\x76\xa3\xb1\xc4\x15\xaa\x86\xae\x8c\x1a\x26\xc8\x93\x25\x74\x4d\xe7\x4b\xfc\x81\xc4\x1a";
$iv = "\x6d\x5d\xb9\xa9\x89\xe9\x46\x0e\x5e\x74\x70\x57\xa1\x4b\x79\x6c";

$out = '';
var_export(openssl_open($sealed, $out, $ekey, $priv, 'AES-128-CBC', $iv));
echo '|';
echo $out === 'hello-seal' ? 'plain-ok' : 'plain-bad';
echo '|';
$bad = '';
var_export(false === @openssl_open($sealed, $bad, $ekey, 'not-a-key', 'AES-128-CBC', $iv));
echo "\n";
