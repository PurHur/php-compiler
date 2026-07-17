<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\HashAlgosRegistry;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for thin standalone AOT hash_algos / hash_hmac_algos — registry loops (#19355, #20050).
 *
 * Nested {@see HashAlgosJitHelper} is skipped when {@see \PHPCompiler\JIT\Context::isThinStandaloneAotMain()}
 * (#20028 Rename shape); this kernel keeps the thin hashtable build in ext/ not lib/JIT/Builtin/.
 * php-src: ext/hash/hash.c — php_hash_algos() / php_hash_hmac_algos()
 */
final class JitHashAlgosKernel
{
    /** Emit full hash_algos() registry; builder must be positioned at the bridge entry block. */
    public static function emitAlgosBody(Context $context, LlvmFunction $fn): void
    {
        self::emitRegistryBody(
            $context,
            $fn,
            HashAlgosRegistry::ALL_ALGOS,
            'hash_algos_kernel'
        );
    }

    /** Emit hash_hmac_algos() registry; builder must be positioned at the bridge entry block. */
    public static function emitHmacAlgosBody(Context $context, LlvmFunction $fn): void
    {
        self::emitRegistryBody(
            $context,
            $fn,
            HashAlgosRegistry::HMAC_ALGOS,
            'hash_hmac_algos_kernel'
        );
    }

    /**
     * @param list<string> $algos
     */
    private static function emitRegistryBody(
        Context $context,
        LlvmFunction $fn,
        array $algos,
        string $prefix
    ): void {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);

        $failBb = $fn->appendBasicBlock($prefix.'_fail');
        $buildBb = $fn->appendBasicBlock($prefix.'_build');
        $context->builder->branchIf($isNull, $failBb, $buildBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullHt);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($buildBb);
        $setAt = $context->lookupFunction('__hashtable__setStringAt');
        foreach ($algos as $index => $algo) {
            $context->builder->call(
                $setAt,
                $ht,
                $i64->constInt($index, false),
                self::literalString($context, $algo)
            );
        }
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
