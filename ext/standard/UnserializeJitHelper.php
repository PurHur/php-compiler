<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * Lowered into JIT/AOT modules for unserialize() runtime (#9163, #20785, php-in-PHP).
 *
 * Always return a fresh {@see Variable} (ArrayPop #12647 shape) — never return a
 * Variable|false union value directly (NestedJIT lowers that as int).
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391).
 * SSOT: {@see VmUnserializeFormat} (php-src ext/standard/var_unserializer.c).
 */
final class UnserializeJitHelper
{
    public static function decode(string $payload): Variable
    {
        $ctx = self::requireActiveContext();
        $parsed = VmUnserializeFormat::decodeToVariableWithContext($ctx, $payload);
        $out = new Variable();
        if (false === $parsed) {
            $out->bool(false);

            return $out;
        }
        $out->copyFrom($parsed);

        return $out;
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
            // NestedJIT lowers resolve() to sg_vm_context load (#17391 / #20785).
            return VmActiveContextJitHelper::resolve();
        }

        return $ctx;
    }
}
