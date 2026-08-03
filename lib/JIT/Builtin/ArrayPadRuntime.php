<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTablePadLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_pad() (#12476, #18121, #26971).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayPadJitHelper} returned a PHP
 * HashTable that is not a native `__hashtable__` — implode/foreach segfault or count 0
 * (#26971). Call-site LLVM via {@see HashTablePadLlvm} (peer ArrayReverseRuntime / #27067).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::pad()} /
 * {@see \PHPCompiler\ext\standard\ArrayPadJitHelper}
 * php-src: ext/standard/array.c — php_array_pad()
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT orphan insert block, peer #26943 / #26884).
 */
final class ArrayPadRuntime
{
    private const ABI_PAD = '__array_pad__copy';

    private const ABI_PAD_TYPED = '__array_pad__copy_typed';

    public static function pad(
        Context $context,
        JITVariable $array,
        Value $length,
        JITVariable $value
    ): Value {
        return self::padWithType($context, $array, $length, $value, null);
    }

    public static function padWithType(
        Context $context,
        JITVariable $array,
        Value $length,
        JITVariable $value,
        ?Value $padType
    ): Value {
        self::ensureLinked($context);
        $ht = self::argToHashtable($context, $array);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);
        if (null === $padType) {
            return $context->builder->call(
                $context->lookupFunction(self::ABI_PAD),
                $ht,
                $length,
                $valuePtr
            );
        }

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PAD_TYPED),
            $ht,
            $length,
            $valuePtr,
            $padType
        );
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
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
        $probePad = $context->module->getNamedFunction(self::ABI_PAD);
        $probeTyped = $context->module->getNamedFunction(self::ABI_PAD_TYPED);
        if (null !== $probePad && $probePad->countBasicBlocks() > 0
            && null !== $probeTyped && $probeTyped->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitPadBridge($context);
        self::emitPadTypedBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitPadBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $probe = $context->module->getNamedFunction(self::ABI_PAD);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_PAD,
                $context->context->functionType($htPtr, false, $htPtr, $i64, $valuePtr)
            );

        $entry = $fn->appendBasicBlock('array_pad_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $padded = HashTablePadLlvm::pad(
            $context,
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($padded);
        $context->registerFunction(self::ABI_PAD, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitPadTypedBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $probe = $context->module->getNamedFunction(self::ABI_PAD_TYPED);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_PAD_TYPED,
                $context->context->functionType($htPtr, false, $htPtr, $i64, $valuePtr, $i64)
            );

        $entry = $fn->appendBasicBlock('array_pad_typed_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $padded = HashTablePadLlvm::padWithType(
            $context,
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $context->builder->returnValue($padded);
        $context->registerFunction(self::ABI_PAD_TYPED, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_PAD, self::ABI_PAD_TYPED] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after ArrayPadRuntime bridge (#26971)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
