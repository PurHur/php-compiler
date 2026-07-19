<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_get_cipher_methods / openssl_get_md_methods registry (#21103).
 *
 * NestedJIT leaf for {@see OpensslMethodsJitHelper} / {@see phpc_openssl_cipher_methods_kernel}
 * (hash_algos #20652 / Fpow #20664 shape — avoids NestedJIT of OpensslCipherRegistry under AOT).
 * php-src: ext/openssl/openssl.c
 */
final class JitOpensslMethodsKernel
{
    public static function invokeCipherMethods(Context $context): Value
    {
        return self::invokeRegistry($context, OpensslCipherRegistry::cipherMethods(false), 'ossl_cipher_inv');
    }

    public static function invokeMdMethods(Context $context): Value
    {
        return self::invokeRegistry($context, OpensslCipherRegistry::mdMethods(false), 'ossl_md_inv');
    }

    /**
     * @param list<string> $names
     */
    private static function invokeRegistry(Context $context, array $names, string $prefix): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $fn = $context->builder->getInsertBlock()->getParent();
        $failBb = $fn->appendBasicBlock($prefix.'_fail');
        $buildBb = $fn->appendBasicBlock($prefix.'_build');
        $doneBb = $fn->appendBasicBlock($prefix.'_done');
        $context->builder->branchIf($isNull, $failBb, $buildBb);

        $context->builder->positionAtEnd($failBb);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($buildBb);
        $setAt = $context->lookupFunction('__hashtable__setStringAt');
        foreach ($names as $index => $name) {
            $context->builder->call(
                $setAt,
                $ht,
                $sizeT->constInt($index, false),
                self::literalString($context, $name)
            );
        }
        $buildEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($htPtr, $prefix.'_ht');
        $phi->addIncoming($nullHt, $failEnd);
        $phi->addIncoming($ht, $buildEnd);

        return $phi;
    }

    private static function literalString(Context $context, string $text): Value
    {
        return $context->builder->load($context->constantStringFromString($text));
    }
}
