<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_change_key_case() via ArrayChangeKeyCaseJitHelper PHP (#12371).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildChangeKeyCaseArray()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::changeKeyCase()}
 * php-src: ext/standard/array.c — php_array_change_key_case()
 */
final class ArrayChangeKeyCaseRuntime
{
    private const ABI_CHANGE = '__array_change_key_case__change';

    private const HELPER_PATH = '/ext/standard/ArrayChangeKeyCaseJitHelper.php';

    private const CHANGE_HELPER = 'PHPCompiler\\ext\\standard\\ArrayChangeKeyCaseJitHelper::changeKeyCase';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHANGE_HELPER,
    ];

    public static function changeKeyCase(Context $context, JITVariable $array, Value $case): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::buildChangeKeyCaseArray($context, $array, $case);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CHANGE),
            $ht,
            $case
        );
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_CHANGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CHANGE,
            'array_change_key_case_bridge_entry',
            [$htPtr, $i64],
            $htPtr,
            self::CHANGE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12371'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_CHANGE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_CHANGE.' missing after ArrayChangeKeyCaseRuntime bridge (#12371)');
        }
        $context->registerFunction(self::ABI_CHANGE, $fn);
    }
}
