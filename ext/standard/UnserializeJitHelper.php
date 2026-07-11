<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * Lowered into JIT/AOT modules for unserialize() runtime (#9163, php-in-PHP).
 *
 * SSOT: {@see VmUnserializeFormat} (php-src ext/standard/var_unserializer.c).
 */
final class UnserializeJitHelper
{
    public static function decode(string $payload): Variable
    {
        $ctx = self::requireActiveContext();
        $result = VmUnserializeFormat::decodeToVariableWithContext($ctx, $payload);
        if (false === $result) {
            $false = new Variable();
            $false->bool(false);

            return $false;
        }

        return $result;
    }

    /** Session wire decode: key|serialized pairs or empty hashtable on failure (#6086). */
    public static function decodeSession(string $payload): HashTable
    {
        $ht = VmSessionSerializer::decodeWireHashTable($payload);

        return $ht ?? new HashTable();
    }

    private static function requireActiveContext(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('unserialize() JIT helper requires active VM context (#9163)');
        }

        return $ctx;
    }
}
