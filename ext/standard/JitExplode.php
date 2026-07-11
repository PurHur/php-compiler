<?php

declare(strict_types=1);

/**
 * Compile-time explode() for JIT/AOT when delimiter, haystack, and limit are known (#14750).
 *
 * Runtime paths route through {@see \PHPCompiler\JIT\Builtin\StringExplode} + ExplodeJitHelper PHP.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Value;

final class JitExplode
{
    /**
     * Compile-time explode for JIT/AOT when delimiter, haystack, and limit are known.
     */
    public static function buildPackedStrings(
        Context $context,
        string $delimiter,
        string $literal,
        int $limit
    ): Value {
        $parts = VmString::explode($delimiter, $literal, $limit);
        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($parts as $i => $part) {
            $slice = $context->builder->load($context->constantStringFromString($part));
            $context->builder->call(
                $setString,
                $ht,
                $sizeT->constInt($i, false),
                $slice
            );
        }

        return $ht;
    }
}
