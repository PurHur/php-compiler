<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * session_encode()/session_decode() for compiled JIT/AOT modules (#9440, php-in-PHP).
 *
 * SSOT: {@see VmSessionSerializer}
 * php-src: ext/session/session.c — php_session_encode / php_session_decode
 */
final class SessionEncodeJitHelper
{
    public static function encodeWire(HashTable $session): ?string
    {
        $result = VmSessionSerializer::encodeWireHashTable($session);

        return false === $result ? null : $result;
    }

    public static function decodeWire(string $payload): ?HashTable
    {
        return VmSessionSerializer::decodeWireHashTable($payload);
    }
}
