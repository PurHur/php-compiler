<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\HashAlgosRegistry;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_hash_algos (issue #11463).
 *
 * php-src: ext/hash/hash.c — php_hash_algos().
 * VM semantics: ext/standard/VmHash::algos().
 */
final class StringHashAlgos
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_hash_algos');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_hash_algos', $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_hash_algos', $ft);
        self::implementHashAlgos($context, $fn);
        $context->registerFunction('__compiler_hash_algos', $fn);
    }

    private static function implementHashAlgos(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hash_algos_entry');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);

        $failBb = $fn->appendBasicBlock('hash_algos_fail');
        $buildBb = $fn->appendBasicBlock('hash_algos_build');
        $context->builder->branchIf($isNull, $failBb, $buildBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullHt);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($buildBb);
        $setAt = $context->lookupFunction('__hashtable__setStringAt');
        foreach (HashAlgosRegistry::ALL_ALGOS as $index => $algo) {
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

    private static function literalString(Context $context, string $text): \PHPLLVM\Value
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
