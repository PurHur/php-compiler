<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableCowLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array union (`+`) via {@see HashTableCowLlvm} (#10533, #23548).
 *
 * Emits `__hashtable__union` LLVM directly — NestedJIT of HashTableJitHelper during
 * TYPE_PLUS / cast scrambled outer CFG. Host/VM SSOT remains
 * {@see \PHPCompiler\VM\HashTable::unionCopy()} / {@see \PHPCompiler\VM\HashTableJitHelper::unionCopy()}.
 *
 * php-src: Zend/zend_operators.c — add_function array union; Zend/zend_hash.c merge
 */
final class HashTableUnionRuntime
{
    private const ABI_UNION = '__hashtable__union';

    public static function union(Context $context, Value $leftHt, Value $rightHt): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_UNION),
            $leftHt,
            $rightHt
        );
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_UNION);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $htPtr, $htPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_UNION, $ft);

        $entry = $fn->appendBasicBlock('hashtable_union_entry');
        $context->registerFunction(self::ABI_UNION, $fn);
        $context->activeFunction = self::ABI_UNION;
        $context->loweringLlvmFunction = $fn instanceof \PHPLLVM\Value\Function_ ? $fn : null;
        $context->builder->positionAtEnd($entry);
        try {
            $result = HashTableCowLlvm::union($context, $fn->getParam(0), $fn->getParam(1));
            $context->builder->returnValue($result);
            self::registerLinkedRuntime($context);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_UNION);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_UNION.' missing after HashTableUnionRuntime (#23548)');
        }
        $context->registerFunction(self::ABI_UNION, $fn);
    }
}
