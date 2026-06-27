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
 * JIT/AOT link for array_diff_assoc() via ArrayDiffAssocJitHelper PHP (#12552).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::arrayDiffAssoc()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_diff_assoc)
 */
final class ArrayDiffAssocRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayDiffAssocJitHelper.php';

    private const DIFF_ASSOC_SINGLE = 'PHPCompiler\\ext\\standard\\ArrayDiffAssocJitHelper::diffAssocSingleCopy';

    private const DIFF_ASSOC_TWO = 'PHPCompiler\\ext\\standard\\ArrayDiffAssocJitHelper::diffAssocTwo';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DIFF_ASSOC_SINGLE,
        self::DIFF_ASSOC_TWO,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function diffAssoc(Context $context, JITVariable $first, JITVariable ...$others): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return ArrayBuiltinHelper::arrayDiffAssoc($context, $first, ...$others);
        }

        foreach ([$first, ...$others] as $arg) {
            if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
                return ArrayBuiltinHelper::arrayDiffAssoc($context, $first, ...$others);
            }
        }

        self::ensureLinked($context);

        $firstHt = ArrayBuiltinHelper::loadHashTable($context, $first);
        if ([] === $others) {
            return self::callDiffAssocSingle($context, $firstHt);
        }

        $result = self::callDiffAssocSingle($context, $firstHt);
        foreach ($others as $other) {
            $nextHt = ArrayBuiltinHelper::loadHashTable($context, $other);
            $result = self::callDiffAssocTwo($context, $result, $nextHt);
        }

        return $result;
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction('__array_diff_assoc__single');
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
        self::implementIfMissing($context, '__array_diff_assoc__single', self::implementDiffAssocSingleBridge(...));
        self::implementIfMissing($context, '__array_diff_assoc__two', self::implementDiffAssocTwoBridge(...));
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
                    '__array_diff_assoc__single' => [$htPtr],
                    '__array_diff_assoc__two' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_diff_assoc bridge: '.$name),
                }
            )
        );
    }

    private static function implementDiffAssocSingleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_diff_assoc_single_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DIFF_ASSOC_SINGLE),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function implementDiffAssocTwoBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_diff_assoc_two_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DIFF_ASSOC_TWO),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function callDiffAssocSingle(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_diff_assoc__single'),
            $ht
        );
    }

    private static function callDiffAssocTwo(Context $context, Value $left, Value $right): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_diff_assoc__two'),
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
            throw new \LogicException($logical.' missing after ArrayDiffAssocJitHelper compile (#12552)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ArrayDiffAssocJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ArrayDiffAssocJitHelper.php parseAndCompile failed (#12552)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#12552)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_diff_assoc__single', '__array_diff_assoc__two'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayDiffAssocRuntime bridge (#12552)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
