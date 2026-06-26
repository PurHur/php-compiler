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
 * JIT/AOT link for array_merge_recursive() via ArrayMergeRecursiveJitHelper PHP (#10183).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::mergeRecursive()}.
 * SSOT: {@see \PHPCompiler\VM\HashTable::mergeRecursiveCopy()}
 * php-src: ext/standard/array.c — php_array_merge_recursive()
 */
final class ArrayMergeRecursiveRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayMergeRecursiveJitHelper.php';

    private const MERGE_SINGLE = 'PHPCompiler\\ext\\standard\\ArrayMergeRecursiveJitHelper::mergeSingleCopy';

    private const MERGE_TWO = 'PHPCompiler\\ext\\standard\\ArrayMergeRecursiveJitHelper::mergeTwo';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MERGE_SINGLE,
        self::MERGE_TWO,
    ];

    public static function mergeRecursive(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_merge_recursive() expects at least 1 argument, 0 given');
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return ArrayBuiltinHelper::mergeRecursive($context, ...$args);
        }

        self::ensureLinked($context);

        $count = \count($args);
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

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction('__array_merge_recursive__single');
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
        self::implementIfMissing($context, '__array_merge_recursive__single', self::implementMergeSingleBridge(...));
        self::implementIfMissing($context, '__array_merge_recursive__two', self::implementMergeTwoBridge(...));
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
                    '__array_merge_recursive__single' => [$htPtr],
                    '__array_merge_recursive__two' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_merge_recursive bridge: '.$name),
                }
            )
        );
    }

    private static function implementMergeSingleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_merge_recursive_single_bridge_entry');
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
        $entry = $fn->appendBasicBlock('array_merge_recursive_two_bridge_entry');
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
            $context->lookupFunction('__array_merge_recursive__single'),
            $ht
        );
    }

    private static function callMergeTwo(Context $context, Value $left, Value $right): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_merge_recursive__two'),
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ArrayMergeRecursiveJitHelper compile (#10183)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ArrayMergeRecursiveJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ArrayMergeRecursiveJitHelper.php parseAndCompile failed (#10183)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10183)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_merge_recursive__single', '__array_merge_recursive__two'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayMergeRecursiveRuntime bridge (#10183)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
