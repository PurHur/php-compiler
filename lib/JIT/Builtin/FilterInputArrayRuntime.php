<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitFilterInputTypeArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for filter_input_array() (#3294, #21937, #34580).
 *
 * NestedJIT helpers return {@see \PHPCompiler\VM\HashTable}|null; ABI is `__hashtable__*`.
 * Null INPUT_* snapshot → boxed null (Zend CLI); ht → `__value__writeHashtable` (peer JitGraphemeStrSplit).
 */
final class FilterInputArrayRuntime
{
    private const ABI = '__filter_input_array__batch';

    private const ABI_FILTER_ID = '__filter_input_array__filter_id';

    private const HELPER_PATH = '/ext/filter/FilterBatchJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterInputArray';

    private const HELPER_FILTER_ID = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterInputArrayByFilterId';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER, self::HELPER_FILTER_ID];

    private static int $boxSerial = 0;

    public static function filter(Context $context, JITVariable $type, JITVariable $definition, int $addEmpty): Value
    {
        self::ensureLinked($context);
        $typeVal = JitFilterInputTypeArg::lower($context, $type, 'filter_input_array');
        $i64 = $context->getTypeFromString('int64');
        $addEmptyVal = $i64->constInt($addEmpty, false);
        if (self::isArrayDefinition($definition)) {
            $defHt = ArrayBuiltinHelper::loadHashTable($context, $definition);
            $htRaw = $context->builder->call(
                $context->lookupFunction(self::ABI),
                $typeVal,
                $defHt,
                $addEmptyVal
            );
        } else {
            $filterId = JitLongArg::lower($context, $definition, 'filter_input_array() definition');
            $htRaw = $context->builder->call(
                $context->lookupFunction(self::ABI_FILTER_ID),
                $typeVal,
                $filterId,
                $addEmptyVal
            );
        }

        return self::boxHashtableOrNull($context, $htRaw);
    }

    private static function isArrayDefinition(JITVariable $definition): bool
    {
        return JITVariable::TYPE_HASHTABLE === $definition->type
            || ArrayBuiltinHelper::isNativeArray($definition->type);
    }

    /** Null ht* → Zend NULL; non-null → array value box (#34580). */
    private static function boxHashtableOrNull(Context $context, Value $htRaw): Value
    {
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);

        $id = (string) (++self::$boxSerial);
        $nullBb = BasicBlockHelper::append($context, 'filter_input_array_null_'.$id);
        $okBb = BasicBlockHelper::append($context, 'filter_input_array_ht_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'filter_input_array_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isNull, $nullBb, $okBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);
        $context->refcount->addref($ht);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'filter_input_array_bridge_entry',
            [$i64, $htPtr, $i64],
            $htPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34580'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FILTER_ID,
            'filter_input_array_filter_id_bridge_entry',
            [$i64, $i64, $i64],
            $htPtr,
            self::HELPER_FILTER_ID,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34580'
        );
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
