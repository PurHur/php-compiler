<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_replace() via ArrayReplaceJitHelper PHP (#12516).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::arrayReplace()}.
 * SSOT: {@see \PHPCompiler\VM\HashTable::replaceCopy()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace)
 */
final class ArrayReplaceRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayReplaceJitHelper.php';

    private const REPLACE_SINGLE = 'PHPCompiler\\ext\\standard\\ArrayReplaceJitHelper::replaceSingleCopy';

    private const REPLACE_TWO = 'PHPCompiler\\ext\\standard\\ArrayReplaceJitHelper::replaceTwo';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REPLACE_SINGLE,
        self::REPLACE_TWO,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function replace(Context $context, JITVariable $first, JITVariable ...$others): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return ArrayBuiltinHelper::arrayReplace($context, $first, ...$others);
        }

        foreach ([$first, ...$others] as $arg) {
            if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
                return ArrayBuiltinHelper::arrayReplace($context, $first, ...$others);
            }
        }

        self::ensureLinked($context);

        $firstHt = ArrayBuiltinHelper::loadHashTable($context, $first);
        if ([] === $others) {
            return self::callReplaceSingle($context, $firstHt);
        }

        $result = self::callReplaceSingle($context, $firstHt);
        foreach ($others as $other) {
            $nextHt = ArrayBuiltinHelper::loadHashTable($context, $other);
            $result = self::callReplaceTwo($context, $result, $nextHt);
        }

        return $result;
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction('__array_replace__single');
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
        self::implementIfMissing($context, '__array_replace__single', self::implementReplaceSingleBridge(...));
        self::implementIfMissing($context, '__array_replace__two', self::implementReplaceTwoBridge(...));
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
                    '__array_replace__single' => [$htPtr],
                    '__array_replace__two' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_replace bridge: '.$name),
                }
            )
        );
    }

    private static function implementReplaceSingleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_replace_single_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::REPLACE_SINGLE),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function implementReplaceTwoBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_replace_two_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::REPLACE_TWO),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function callReplaceSingle(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_replace__single'),
            $ht
        );
    }

    private static function callReplaceTwo(Context $context, Value $left, Value $right): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_replace__two'),
            $left,
            $right
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ArrayReplaceJitHelper compile (#12516)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ArrayReplaceJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ArrayReplaceJitHelper.php parseAndCompile failed (#12516)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#12516)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_replace__single', '__array_replace__two'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayReplaceRuntime bridge (#12516)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
