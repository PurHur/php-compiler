<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_intersect() via ArrayIntersectJitHelper PHP (#12529, #23627).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ArrayDiff #23116).
 * Standalone AOT compiles {@see ArrayIntersectJitHelper} via nested JIT bridges (#14398); embed uses same PHP path.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_intersect)
 */
final class ArrayIntersectRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayIntersectJitHelper.php';

    private const INTERSECT_SINGLE = 'PHPCompiler\\ext\\standard\\ArrayIntersectJitHelper::intersectSingleCopy';

    private const INTERSECT_TWO = 'PHPCompiler\\ext\\standard\\ArrayIntersectJitHelper::intersectTwo';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INTERSECT_SINGLE,
        self::INTERSECT_TWO,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function intersect(Context $context, JITVariable $first, JITVariable ...$others): Value
    {
        self::ensureLinked($context);

        $firstHt = self::argToHashtable($context, $first);
        if ([] === $others) {
            return self::callIntersectSingle($context, $firstHt);
        }

        $result = self::callIntersectSingle($context, $firstHt);
        foreach ($others as $other) {
            $nextHt = self::argToHashtable($context, $other);
            $result = self::callIntersectTwo($context, $result, $nextHt);
        }

        return $result;
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__array_intersect__single');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__array_intersect__single', self::implementIntersectSingleBridge(...));
        self::implementIfMissing($context, '__array_intersect__two', self::implementIntersectTwoBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $htPtr,
                false,
                ...match ($name) {
                    '__array_intersect__single' => [$htPtr],
                    '__array_intersect__two' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_intersect bridge: '.$name),
                }
            )
        );
    }

    private static function implementIntersectSingleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_intersect_single_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INTERSECT_SINGLE),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function implementIntersectTwoBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_intersect_two_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INTERSECT_TWO),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function callIntersectSingle(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_intersect__single'),
            $ht
        );
    }

    private static function callIntersectTwo(Context $context, Value $left, Value $right): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_intersect__two'),
            $left,
            $right
        );
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23627');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23627'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_intersect__single', '__array_intersect__two'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayIntersectRuntime bridge (#12529)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
