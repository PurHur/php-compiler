<?php

declare(strict_types=1);

$pem = <<<'PEM'
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

$file = '/tmp/phpc_pkey_export_to_file_32705.pem';
var_export(openssl_pkey_export_to_file($pem, $file));
echo '|';
$written = @file_get_contents($file);
echo (\is_string($written) && str_contains($written, 'BEGIN') && str_contains($written, 'PRIVATE KEY') && str_contains($written, 'END'))
    ? 'file-ok'
    : 'file-bad';
echo '|';
var_export(openssl_pkey_export_to_file('not-a-key', $file));
@unlink($file);
echo "\n";
