<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_unshift() via ArrayUnshiftJitHelper PHP (#12717, #22818).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ArrayPush #22801).
 * Standalone AOT compiles {@see ArrayUnshiftJitHelper} via nested JIT bridges (#14316, #17580).
 * SSOT: {@see \PHPCompiler\ext\standard\array_unshift}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_unshift)
 */
final class ArrayUnshiftRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayUnshiftJitHelper.php';

    private const COUNT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayUnshiftJitHelper::countElements';

    private const PREPEND_HELPER = 'PHPCompiler\\ext\\standard\\ArrayUnshiftJitHelper::unshiftFromList';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COUNT_HELPER,
        self::PREPEND_HELPER,
    ];

    public static function unshift(Context $context, JITVariable $array, JITVariable ...$values): Value
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $native = ArrayBuiltinHelper::isNativeArray($array->type);
        if (0 === \count($values)) {
            $count = self::callCount($context, $ht);
        } else {
            $valuesHt = HashTableHelper::alloc($context);
            foreach ($values as $value) {
                ArrayBuiltinHelper::appendElement($context, $valuesHt, $value);
            }
            $count = self::callPrepend($context, $ht, $valuesHt);
        }
        if ($native) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }

        return $count;
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__array_unshift__count');
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
        self::implementIfMissing($context, '__array_unshift__count', self::implementCountBridge(...));
        self::implementIfMissing($context, '__array_unshift__prepend', self::implementPrependBridge(...));
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
        $i64 = $context->getTypeFromString('int64');

        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $i64,
                false,
                ...match ($name) {
                    '__array_unshift__count' => [$htPtr],
                    '__array_unshift__prepend' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_unshift bridge: '.$name),
                }
            )
        );
    }

    private static function implementCountBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_unshift_count_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $countRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COUNT_HELPER),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceBridgeResult($context, $countRaw, $context->getTypeFromString('int64')));
    }

    private static function implementPrependBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_unshift_prepend_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $countRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PREPEND_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceBridgeResult($context, $countRaw, $context->getTypeFromString('int64')));
    }

    private static function callCount(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_unshift__count'),
            $ht
        );
    }

    private static function callPrepend(Context $context, Value $ht, Value $valuesHt): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_unshift__prepend'),
            $ht,
            $valuesHt
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22818');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22818'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_unshift__count', '__array_unshift__prepend'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayUnshiftRuntime bridge (#12717)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
