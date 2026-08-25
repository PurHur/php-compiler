<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for filter_var_array() (#3294, #21937, #34574).
 *
 * NestedJIT helpers return {@see \PHPCompiler\VM\HashTable}; ABI is `__hashtable__*`
 * (ArrayChunk pattern). TYPE_VALUE FILTER_* literals take the int-id path (#34574).
 */
final class FilterVarArrayRuntime
{
    private const ABI = '__filter_var_array__batch';

    private const ABI_FILTER_ID = '__filter_var_array__filter_id';

    private const HELPER_PATH = '/ext/filter/FilterBatchJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterVarArray';

    private const HELPER_FILTER_ID = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterVarArrayByFilterId';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER, self::HELPER_FILTER_ID];

    public static function filter(Context $context, JITVariable $data, JITVariable $definition, int $addEmpty): Value
    {
        self::ensureLinked($context);
        $dataHt = ArrayBuiltinHelper::loadHashTable($context, $data);
        $i64 = $context->getTypeFromString('int64');
        $addEmptyVal = $i64->constInt($addEmpty, false);
        if (self::isArrayDefinition($definition)) {
            $defHt = ArrayBuiltinHelper::loadHashTable($context, $definition);
            $htRaw = $context->builder->call(
                $context->lookupFunction(self::ABI),
                $dataHt,
                $defHt,
                $addEmptyVal
            );
        } else {
            $filterId = JitLongArg::lower($context, $definition, 'filter_var_array() definition');
            $htRaw = $context->builder->call(
                $context->lookupFunction(self::ABI_FILTER_ID),
                $dataHt,
                $filterId,
                $addEmptyVal
            );
        }

        return JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
    }

    private static function isArrayDefinition(JITVariable $definition): bool
    {
        return JITVariable::TYPE_HASHTABLE === $definition->type
            || ArrayBuiltinHelper::isNativeArray($definition->type);
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        $probeId = $context->module->getNamedFunction(self::ABI_FILTER_ID);
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && null !== $probeId && $probeId->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
            $context->registerFunction(self::ABI_FILTER_ID, $probeId);

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
            self::ABI,
            'filter_var_array_bridge_entry',
            [$htPtr, $htPtr, $i64],
            $htPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34574'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FILTER_ID,
            'filter_var_array_filter_id_bridge_entry',
            [$htPtr, $i64, $i64],
            $htPtr,
            self::HELPER_FILTER_ID,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34574'
        );
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
