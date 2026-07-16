<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for (array) cast via CastJitHelper / SplArrayCastJitHelper (#10046, #10244, #19631).
 *
 * php-src: Zend/zend_operators.c — convert_to_array
 * SSOT: {@see \PHPCompiler\VM\CastSupport}, {@see \PHPCompiler\VM\CastJitHelper}
 */
final class CastArrayRuntime
{
    private const BOOL_HELPER_PATH = '/VM/CastJitHelper.php';

    private const SPL_HELPER_PATH = '/VM/SplArrayCastJitHelper.php';

    private const BOOL_EMPTY_HELPER = 'PHPCompiler\\VM\\CastJitHelper::boolYieldsEmptyArray';

    private const SPL_TRY_HELPER = 'PHPCompiler\\VM\\SplArrayCastJitHelper::tryArrayCastArgv';

    private const BOOL_ABI = '__cast__boolYieldsEmptyArray';

    private const SPL_TRY_ABI = '__cast__trySplArrayCast';

    /** @var list<string> */
    private const BOOL_COMPILED_HELPERS = [
        self::BOOL_EMPTY_HELPER,
    ];

    /** @var list<string> */
    private const SPL_COMPILED_HELPERS = [
        self::SPL_TRY_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementBoolEmpty($context);
        self::implementTrySplArrayCast($context);
    }

    public static function callBoolYieldsEmptyArray(Context $context, Value $boolI1): Value
    {
        self::implementBoolEmpty($context);
        $fn = $context->lookupFunction(self::BOOL_ABI);
        $i1 = $context->getTypeFromString('int1');
        $boolArg = $context->builder->trunc($boolI1, $i1);

        return $context->builder->call($fn, $boolArg);
    }

    /** Object operand → array if ArrayObject/ArrayIterator cast applies, else null. */
    public static function callTrySplArrayCast(Context $context, Value $operandValuePtr): Value
    {
        self::implementTrySplArrayCast($context);

        return $context->builder->call(
            $context->lookupFunction(self::SPL_TRY_ABI),
            $operandValuePtr
        );
    }

    private static function implementBoolEmpty(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::BOOL_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::BOOL_ABI, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::BOOL_ABI,
            'cast_bool_empty_bridge_entry',
            [$i1],
            $i1,
            self::BOOL_EMPTY_HELPER,
            self::BOOL_HELPER_PATH,
            self::BOOL_COMPILED_HELPERS,
            '#10244'
        );
        $context->builder->clearInsertionPosition();
    }

    private static function implementTrySplArrayCast(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::SPL_TRY_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::SPL_TRY_ABI, $probe);

            return;
        }

        // Array cast dup must resolve HashTableJitHelper::duplicateCopy (#18451 / #19631).
        HashTableDuplicateRuntime::ensureLinked($context);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::SPL_TRY_ABI,
            'cast_try_spl_array_bridge_entry',
            [$valuePtr],
            $valuePtr,
            self::SPL_TRY_HELPER,
            self::SPL_HELPER_PATH,
            self::SPL_COMPILED_HELPERS,
            '#19631'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
