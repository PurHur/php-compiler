<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * stream_socket_pair() for compiled JIT/AOT modules (#13710, php-in-PHP).
 *
 * SSOT: {@see VmStreamSocketPairNative::pair()}
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_socket_pair)
 */
final class StreamSocketPairJitHelper
{
    /** @return HashTable|null null when pair() fails (Zend false) */
    public static function pairArgv(int $domain, int $type, int $protocol): ?HashTable
    {
        $pair = VmStreamSocketPairNative::pair($domain, $type, $protocol);
        if (false === $pair) {
            return null;
        }

        [$handle0, $handle1] = $pair;
        if (false === $handle0 || false === $handle1) {
            return null;
        }

        $ht = new HashTable();
        $slot0 = new Variable();
        $slot0->int($handle0);
        $ht->addIndex(0, $slot0);
        $slot1 = new Variable();
        $slot1->int($handle1);
        $ht->addIndex(1, $slot1);

        return $ht;
    }
}
