<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for range() int lowering via RangeIntJitHelper PHP (#13502).
 *
 * Standalone AOT keeps LLVM in {@see HashTableHelper::buildIntegerRange()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmRange::intRangeTable()}
 * php-src: ext/standard/array.c — php_range()
 */
final class RangeIntRuntime
{
    private const ABI_RANGE = '__range_int__copy';

    private const HELPER_PATH = '/ext/standard/RangeIntJitHelper.php';

    private const RANGE_HELPER = 'PHPCompiler\\ext\\standard\\RangeIntJitHelper::intRangeCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RANGE_HELPER,
    ];

    public static function intRange(Context $context, Value $start, Value $end, Value $step): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return HashTableHelper::buildIntegerRange($context, $start, $end, $step);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_RANGE),
            $start,
            $end,
            $step
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

        $probe = $context->module->getNamedFunction(self::ABI_RANGE);
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
            self::ABI_RANGE,
            'range_int_bridge_entry',
            [$i64, $i64, $i64],
            $htPtr,
            self::RANGE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13502'
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
        $fn = $context->module->getNamedFunction(self::ABI_RANGE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_RANGE.' missing after RangeIntRuntime bridge (#13502)');
        }
        $context->registerFunction(self::ABI_RANGE, $fn);
    }
}
