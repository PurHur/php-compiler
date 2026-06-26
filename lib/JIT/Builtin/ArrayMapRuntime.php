<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_map() single-array paths via ArrayMapJitHelper PHP (#10183).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildMapArray()}.
 * Closure callbacks still use LLVM until a VM bridge exists.
 * SSOT: {@see \PHPCompiler\ext\standard\array_map}
 * php-src: ext/standard/array.c — php_array_map()
 */
final class ArrayMapRuntime
{
    private const HELPER_PATH = '/ext/standard/ArrayMapJitHelper.php';

    private const MAP_NULL = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapNullIdentity';

    private const MAP_BUILTIN = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapWithBuiltin';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MAP_NULL,
        self::MAP_BUILTIN,
    ];

    public static function mapSingle(Context $context, JITVariable $callback, JITVariable $array): Value
    {
        if (!ArrayMapCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            return ArrayBuiltinHelper::buildMapArrayWithClosure($context, $callback, $array);
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return ArrayBuiltinHelper::buildMapArray($context, $callback, $array);
        }

        self::ensureLinked($context);
        $ht = self::argToHashtable($context, $array);
        if (JITVariable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            return self::callMapNull($context, $ht);
        }
        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }

        return self::callMapBuiltin($context, $ht, $context->constantFromString($name));
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

        $probe = $context->module->getNamedFunction('__array_map__null');
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
        self::implementIfMissing($context, '__array_map__null', self::implementMapNullBridge(...));
        self::implementIfMissing($context, '__array_map__builtin', self::implementMapBuiltinBridge(...));
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
        $strPtr = $context->getTypeFromString('__string__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $htPtr,
                false,
                ...match ($name) {
                    '__array_map__null' => [$htPtr],
                    '__array_map__builtin' => [$htPtr, $strPtr],
                    default => throw new \LogicException('unknown array_map bridge: '.$name),
                }
            )
        );
    }

    private static function implementMapNullBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_map_null_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MAP_NULL),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function implementMapBuiltinBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_map_builtin_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MAP_BUILTIN),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function callMapNull(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_map__null'),
            $ht
        );
    }

    private static function callMapBuiltin(Context $context, Value $ht, Value $namePtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__array_map__builtin'),
            $ht,
            $namePtr
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
            throw new \LogicException($logical.' missing after ArrayMapJitHelper compile (#10183)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ArrayMapJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ArrayMapJitHelper.php parseAndCompile failed (#10183)');
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
        foreach (['__array_map__null', '__array_map__builtin'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayMapRuntime bridge (#10183)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
