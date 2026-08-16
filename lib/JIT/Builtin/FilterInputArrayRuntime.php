<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitFilterInputTypeArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT/AOT link for filter_input_array() (#3294, #21937). */
final class FilterInputArrayRuntime
{
    private const ABI = '__filter_input_array__batch';

    private const ABI_FILTER_ID = '__filter_input_array__filter_id';

    private const HELPER_PATH = '/ext/filter/FilterBatchJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterInputArray';

    private const HELPER_FILTER_ID = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterInputArrayByFilterId';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER, self::HELPER_FILTER_ID];

    public static function filter(Context $context, JITVariable $type, JITVariable $definition, int $addEmpty): Value
    {
        self::ensureLinked($context);
        $typeVal = JitFilterInputTypeArg::lower($context, $type, 'filter_input_array');
        $i64 = $context->getTypeFromString('int64');
        if (self::isIntDefinition($definition)) {
            $filterId = JitLongArg::lower($context, $definition, 'filter_input_array() definition');

            return $context->builder->call(
                $context->lookupFunction(self::ABI_FILTER_ID),
                $typeVal,
                $filterId,
                $i64->constInt($addEmpty, false)
            );
        }
        $defHt = ArrayBuiltinHelper::loadHashTable($context, $definition);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $typeVal,
            $defHt,
            $i64->constInt($addEmpty, false)
        );
    }

    private static function isIntDefinition(JITVariable $definition): bool
    {
        return JITVariable::TYPE_NATIVE_LONG === $definition->type
            || JITVariable::TYPE_NATIVE_BOOL === $definition->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $definition->type;
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
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'filter_input_array_bridge_entry',
            [$i64, $htPtr, $i64],
            $valuePtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#3294'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FILTER_ID,
            'filter_input_array_filter_id_bridge_entry',
            [$i64, $i64, $i64],
            $valuePtr,
            self::HELPER_FILTER_ID,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21937'
        );
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
