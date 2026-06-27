<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_push() via ArrayPushJitHelper PHP (#12719).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::push()}.
 * SSOT: {@see \PHPCompiler\ext\standard\array_push}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_push)
 */
final class ArrayPushRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayPushJitHelper.php';

    private const COUNT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPushJitHelper::countElements';

    private const APPEND_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPushJitHelper::pushFromList';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COUNT_HELPER,
        self::APPEND_HELPER,
    ];

    public static function push(Context $context, JITVariable $array, JITVariable ...$values): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::push($context, $array, ...$values);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        if (0 === \count($values)) {
            return self::callCount($context, $ht);
        }

        $valuesHt = HashTableHelper::alloc($context);
        foreach ($values as $value) {
            ArrayBuiltinHelper::appendElement($context, $valuesHt, $value);
        }

        return self::callAppend($context, $ht, $valuesHt);
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

        $probe = $context->module->getNamedFunction('__array_push__count');
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
        self::implementIfMissing($context, '__array_push__count', self::implementCountBridge(...));
        self::implementIfMissing($context, '__array_push__append', self::implementAppendBridge(...));
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
                    '__array_push__count' => [$htPtr],
                    '__array_push__append' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_push bridge: '.$name),
                }
            )
        );
    }

    private static function implementCountBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_push_count_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $countRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COUNT_HELPER),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceBridgeResult($context, $countRaw, $context->getTypeFromString('int64')));
    }

    private static function implementAppendBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_push_append_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $countRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::APPEND_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceBridgeResult($context, $countRaw, $context->getTypeFromString('int64')));
    }

    private static function callCount(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_push__count'),
            $ht
        );
    }

    private static function callAppend(Context $context, Value $ht, Value $valuesHt): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_push__append'),
            $ht,
            $valuesHt
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ArrayPushJitHelper compile (#12719)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ArrayPushJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ArrayPushJitHelper.php parseAndCompile failed (#12719)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#12719)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_push__count', '__array_push__append'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayPushRuntime bridge (#12719)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
