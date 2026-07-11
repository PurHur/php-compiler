<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_multisort() coupled packed paths via MultisortJitHelper PHP (#15667).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\array_multisort}
 * php-src: ext/standard/array.c — php_array_multisort
 */
final class MultisortRuntime
{
    private const ABI_MULTISORT_PACKED = '__multisort__packed';

    private const HELPER_PATH = '/ext/standard/MultisortJitHelper.php';

    private const MULTISORT_PACKED_HELPER = 'PHPCompiler\\ext\\standard\\MultisortJitHelper::multisortPacked';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MULTISORT_PACKED_HELPER,
    ];

    /**
     * @param list<JITVariable> $arrays
     */
    public static function multisortPacked(Context $context, array $arrays, bool $descending): void
    {
        if (\count($arrays) < 2) {
            throw new \LogicException(
                'array_multisort() requires at least two array arguments in this compiler build'
            );
        }

        $sources = [];
        foreach ($arrays as $i => $array) {
            if ($i > 0 && ArrayBuiltinHelper::isNativeArray($array->type)) {
                throw new \LogicException(
                    'array_multisort() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to variables first'
                );
            }
            $sources[] = self::argToHashtable($context, $array);
        }

        self::ensureLinked($context);
        $packed = self::packHashtablePtrArray($context, $sources);
        $context->builder->call(
            $context->lookupFunction(self::ABI_MULTISORT_PACKED),
            HashTableHelper::loadHashtablePointer($context, $packed),
            $context->getTypeFromString('int1')->constInt($descending ? 1 : 0, false)
        );
        foreach ($arrays as $i => $array) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $sources[$i]);
        }
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
        if (self::bridgesComplete($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $void = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MULTISORT_PACKED,
            'multisort_packed_bridge_entry',
            [$htPtr, $i1],
            $void,
            self::MULTISORT_PACKED_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15667'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }

    /**
     * @param list<Value> $sources
     */
    private static function packHashtablePtrArray(Context $context, array $sources): JITVariable
    {
        $vars = [];
        foreach ($sources as $source) {
            $vars[] = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $source);
        }

        return HashTableHelper::packVariables($context, $vars);
    }

    private static function bridgesComplete(Context $context): bool
    {
        $probe = $context->module->getNamedFunction(self::ABI_MULTISORT_PACKED);
        if (null === $probe || 0 === $probe->countBasicBlocks()) {
            return false;
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_MULTISORT_PACKED);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_MULTISORT_PACKED.' missing after MultisortRuntime bridge (#15667)');
        }
        $context->registerFunction(self::ABI_MULTISORT_PACKED, $fn);
    }
}
