<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * Lowered into JIT/AOT modules for unserialize() runtime (#9163, #20785, #27030, php-in-PHP).
 *
 * Integer wire (`i:N;`) NestedJIT-decodes as bare int (bridge boxes to `__value__*`).
 * Object wire (`O:…`) uses {@see UnserializeObjectNestedJitHelper} + LLVM materialize (#27030).
 * Do not call {@see requireActiveContext} from NestedJIT decode — thin AOT TypeError (#27030).
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391).
 * php-src: ext/standard/var_unserializer.c
 */
final class UnserializeJitHelper
{
    /**
     * Integer wire decode for NestedJIT (#20785). Non-int payloads currently return 0.
     */
    public static function decode(string $payload): int
    {
        $len = \strlen($payload);
        if ($len < 4 || 'i' !== $payload[0] || ':' !== $payload[1] || ';' !== $payload[$len - 1]) {
            return 0;
        }
        $digits = \substr($payload, 2, $len - 3);
        if ('' === $digits) {
            return 0;
        }
        $i = 0;
        if ('-' === $digits[0] || '+' === $digits[0]) {
            $i = 1;
        }
        if ($i >= \strlen($digits)) {
            return 0;
        }
        for ($n = \strlen($digits); $i < $n; ++$i) {
            $c = $digits[$i];
            if ($c < '0' || $c > '9') {
                return 0;
            }
        }

        return (int) $digits;
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
            return VmActiveContextJitHelper::resolve();
        }

        return $ctx;
    }
}
