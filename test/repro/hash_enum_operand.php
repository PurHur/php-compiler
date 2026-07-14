<?php

declare(strict_types=1);

/**
 * Issue #8826 — digest/encoding builtins reject backed enum case operands (php-src-strict).
 *
 * php-src: ext/standard/string.c, ext/standard/hash.c
 */
enum E: string
{
    case A = 'x';
}

foreach ([
    'md5' => static fn () => md5(E::A),
    'sha1' => static fn () => sha1(E::A),
    'crc32' => static fn () => crc32(E::A),
    'bin2hex' => static fn () => bin2hex(E::A),
    'base64_encode' => static fn () => base64_encode(E::A),
    'hash' => static fn () => hash('md5', E::A),
] as $name => $call) {
    try {
        $call();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo "$name: ", $e->getMessage(), "\n";
    }
}
