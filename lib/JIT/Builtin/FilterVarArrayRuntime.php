<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitFilterInputTypeArg;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT/AOT link for filter_var_array() (#3294). */
final class FilterVarArrayRuntime
{
    private const ABI = '__filter_var_array__batch';

    private const HELPER_PATH = '/ext/filter/FilterBatchJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\filter\\FilterBatchJitHelper::filterVarArray';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER];

    public static function filter(Context $context, JITVariable $data, JITVariable $definition, int $addEmpty): Value
    {
        self::ensureLinked($context);
        $dataHt = ArrayBuiltinHelper::loadHashTable($context, $data);
        $defHt = ArrayBuiltinHelper::loadHashTable($context, $definition);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $dataHt,
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
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'filter_var_array_bridge_entry',
            [$htPtr, $htPtr, $i64],
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
