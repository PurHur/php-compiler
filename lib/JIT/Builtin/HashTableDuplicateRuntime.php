<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableCowLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT link for hashtable COW duplicate via {@see HashTableCowLlvm} (#18451, #23548).
 *
 * Emits `__hashtable__duplicate` LLVM directly — NestedJIT of HashTableJitHelper mid-cast
 * scrambled outer CFG (module verify). Host/VM SSOT remains {@see \PHPCompiler\VM\HashTable::duplicate()}
 * / {@see \PHPCompiler\VM\HashTableJitHelper::duplicateCopy()}.
 *
 * php-src: Zend/zend_hash.c — zend_array_dup; convert_to_array COW in zend_operators.c
 */
final class HashTableDuplicateRuntime
{
    private const ABI_DUPLICATE = '__hashtable__duplicate';

    public static function duplicate(Context $context, Value $srcHt): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_DUPLICATE),
            $srcHt
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
        $probe = $context->module->getNamedFunction(self::ABI_DUPLICATE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $htPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_DUPLICATE, $ft);

        $entry = $fn->appendBasicBlock('hashtable_duplicate_entry');
        $context->registerFunction(self::ABI_DUPLICATE, $fn);
        $context->activeFunction = self::ABI_DUPLICATE;
        $context->loweringLlvmFunction = $fn instanceof \PHPLLVM\Value\Function_ ? $fn : null;
        $context->builder->positionAtEnd($entry);
        try {
            $result = HashTableCowLlvm::duplicate($context, $fn->getParam(0));
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
        $fn = $context->module->getNamedFunction(self::ABI_DUPLICATE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_DUPLICATE.' missing after HashTableDuplicateRuntime (#23548)');
        }
        $context->registerFunction(self::ABI_DUPLICATE, $fn);
    }
}
