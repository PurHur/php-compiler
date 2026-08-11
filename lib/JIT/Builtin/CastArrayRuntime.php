<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for (array) cast via SplArrayCastJitHelper (#10046, #19631).
 *
 * php-src: Zend/zend_operators.c — convert_to_array
 * SSOT: {@see \PHPCompiler\VM\CastSupport}
 *
 * Bool empty-array branching was removed in #30097 — Zend wraps false like true.
 */
final class CastArrayRuntime
{
    private const SPL_HELPER_PATH = '/VM/SplArrayCastJitHelper.php';

    private const SPL_TRY_HELPER = 'PHPCompiler\\VM\\SplArrayCastJitHelper::tryArrayCastArgv';

    private const SPL_TRY_ABI = '__cast__trySplArrayCast';

    /** @var list<string> */
    private const SPL_COMPILED_HELPERS = [
        self::SPL_TRY_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementTrySplArrayCast($context);
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
