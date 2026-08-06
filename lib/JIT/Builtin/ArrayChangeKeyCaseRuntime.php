<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableChangeKeyCaseLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_change_key_case() (#12371, #14530, #18024, #27183).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayChangeKeyCaseJitHelper}
 * segfaulted under HELPER_RUNTIME_O=0 (#27183). Call-site LLVM via
 * {@see HashTableChangeKeyCaseLlvm} (peer ArrayFillKeysRuntime / #27127,
 * ArrayFlipRuntime / #26970).
 *
 * Operands materialize via {@see ArrayBuiltinHelper::loadHashTable()} (preserves
 * string keys; #25500). Literal `json_encode(array_change_key_case(…))` is folded
 * in {@see \PHPCompiler\ext\standard\JitJsonEncodeCompileTime} (peer #27072).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::changeKeyCase()} /
 * {@see \PHPCompiler\ext\standard\ArrayChangeKeyCaseJitHelper}
 * php-src: ext/standard/array.c — php_array_change_key_case()
 */
final class ArrayChangeKeyCaseRuntime
{
    private const ABI_CHANGE = '__array_change_key_case__llvm';

    public static function changeKeyCase(Context $context, JITVariable $array, Value $case): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CHANGE),
            ArrayBuiltinHelper::loadHashTable($context, $array),
            $case
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
        $probe = $context->module->getNamedFunction(self::ABI_CHANGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StreamGlobalsJit::implementThinIsResource($context);
        self::emitChangeBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitChangeBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $probe = $context->module->getNamedFunction(self::ABI_CHANGE);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_CHANGE,
                $context->context->functionType($htPtr, false, $htPtr, $i64)
            );

        $entry = $fn->appendBasicBlock('array_change_key_case_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $changed = HashTableChangeKeyCaseLlvm::changeKeyCase(
            $context,
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($changed);
        $context->registerFunction(self::ABI_CHANGE, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_CHANGE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_CHANGE.' missing after ArrayChangeKeyCaseRuntime bridge (#27183)');
        }
        $context->registerFunction(self::ABI_CHANGE, $fn);
    }
}
