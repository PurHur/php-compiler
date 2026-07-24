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
 * JIT/AOT link for array_merge() via ArrayMergeJitHelper PHP (#10183, #22954).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ArrayPush #22801).
 * Standalone AOT compiles {@see ArrayMergeJitHelper} via nested JIT bridges (#14276); embed uses same PHP path.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray}
 * php-src: ext/standard/array.c — php_array_merge()
 */
final class ArrayMergeRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayMergeJitHelper.php';

    private const MERGE_SINGLE = 'PHPCompiler\\ext\\standard\\ArrayMergeJitHelper::mergeSingleCopy';

    private const MERGE_TWO = 'PHPCompiler\\ext\\standard\\ArrayMergeJitHelper::mergeTwo';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MERGE_SINGLE,
        self::MERGE_TWO,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function merge(Context $context, JITVariable ...$args): Value
    {
        self::ensureLinked($context);

        $count = \count($args);
        if ($count < 1) {
            return ArrayBuiltinHelper::emptyArray($context);
        }

        $firstHt = self::argToHashtable($context, $args[0]);
        if (1 === $count) {
            return self::callMergeSingle($context, $firstHt);
        }

        $result = self::callMergeSingle($context, $firstHt);
        for ($i = 1; $i < $count; ++$i) {
            $nextHt = self::argToHashtable($context, $args[$i]);
            $result = self::callMergeTwo($context, $result, $nextHt);
        }

        return $result;
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__array_merge__single');
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
        self::implementIfMissing($context, '__array_merge__single', self::implementMergeSingleBridge(...));
        self::implementIfMissing($context, '__array_merge__two', self::implementMergeTwoBridge(...));
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
                    '__array_merge__single' => [$htPtr],
                    '__array_merge__two' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_merge bridge: '.$name),
                }
            )
        );
    }

    private static function implementMergeSingleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_merge_single_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MERGE_SINGLE),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function implementMergeTwoBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_merge_two_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MERGE_TWO),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function callMergeSingle(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_merge__single'),
            $ht
        );
    }

    private static function callMergeTwo(Context $context, Value $left, Value $right): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_merge__two'),
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22954');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22954'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_merge__single', '__array_merge__two'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayMergeRuntime bridge (#10183)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
