<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayFlipLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_flip() (#12329, #26970).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayFlipJitHelper} fatals on
 * HashTable::iterateKeyed (#21981). Call-site LLVM via {@see ArrayFlipLlvm} (pre-#17922 walk).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::flip()}
 * php-src: ext/standard/array.c — php_array_flip()
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayFlipRuntime
{
    private const ABI_FLIP = '__array_flip__flip';

    public static function flip(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FLIP),
            ArrayBuiltinHelper::loadHashTable($context, $array)
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
        $probe = $context->module->getNamedFunction(self::ABI_FLIP);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitFlipBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitFlipBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction(self::ABI_FLIP);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_FLIP,
                $context->context->functionType($htPtr, false, $htPtr)
            );

        $entry = $fn->appendBasicBlock('array_flip_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $flipped = ArrayFlipLlvm::flipHashTable($context, $src);
        $context->builder->returnValue($flipped);
        $context->registerFunction(self::ABI_FLIP, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_FLIP);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FLIP.' missing after ArrayFlipRuntime bridge (#26970)');
        }
        $context->registerFunction(self::ABI_FLIP, $fn);
    }
}
