<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitFilterInputTypeArg;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT/AOT link for filter_input_array() (#3294). */
final class FilterInputArrayRuntime
{
    private const ABI = '__filter_input_array__batch';

    private const HELPER_PATH = '/ext/filter/FilterBatchJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterInputArray';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER];

    public static function filter(Context $context, JITVariable $type, JITVariable $definition, int $addEmpty): Value
    {
        self::ensureLinked($context);
        $typeVal = JitFilterInputTypeArg::lower($context, $type);
        $defHt = ArrayBuiltinHelper::loadHashTable($context, $definition);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $typeVal,
            $defHt,
            $i64->constInt($addEmpty, false)
        );
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

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
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
