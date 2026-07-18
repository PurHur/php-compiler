<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\HashAlgosRegistry;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for hash_algos / hash_hmac_algos registry (#19355, #20050, #20652).
 *
 * NestedJIT leaf for {@see HashAlgosJitHelper} / {@see phpc_hash_algos_kernel}
 * (Rename #20603 / Fpow #20664 shape — no thin standalone ABI fork on the bridge).
 * php-src: ext/hash/hash.c — php_hash_algos() / php_hash_hmac_algos()
 */
final class JitHashAlgosKernel
{
    /** Mid-stream hashtable build for NestedJIT / Internal::call. */
    public static function invokeAlgos(Context $context): Value
    {
        return self::invokeRegistry($context, HashAlgosRegistry::ALL_ALGOS);
    }

    /** Mid-stream hashtable build for NestedJIT / Internal::call. */
    public static function invokeHmacAlgos(Context $context): Value
    {
        return self::invokeRegistry($context, HashAlgosRegistry::HMAC_ALGOS);
    }

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
    private static function invokeRegistry(Context $context, array $algos): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $fn = $context->builder->getInsertBlock()->getParent();
        $failBb = $fn->appendBasicBlock('hash_algos_inv_fail');
        $buildBb = $fn->appendBasicBlock('hash_algos_inv_build');
        $doneBb = $fn->appendBasicBlock('hash_algos_inv_done');
        $context->builder->branchIf($isNull, $failBb, $buildBb);

        $context->builder->positionAtEnd($failBb);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($buildBb);
        $setAt = $context->lookupFunction('__hashtable__setStringAt');
        foreach ($algos as $index => $algo) {
            $context->builder->call(
                $setAt,
                $ht,
                $sizeT->constInt($index, false),
                self::literalString($context, $algo)
            );
        }
        $buildEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($htPtr, 'hash_algos_inv_ht');
        $phi->addIncoming($nullHt, $failEnd);
        $phi->addIncoming($ht, $buildEnd);

        return $phi;
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
        $sizeT = $context->getTypeFromString('size_t');
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
                $sizeT->constInt($index, false),
                self::literalString($context, $algo)
            );
        }
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function literalString(Context $context, string $text): Value
    {
        // Peer JitExplode::buildPackedStrings — constantStringFromString + load,
        // not __string__init(constantFromString) (AOT packed-list / in_array break, #20652).
        return $context->builder->load($context->constantStringFromString($text));
    }
}
