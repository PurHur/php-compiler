<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\ArrayMapLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_map() via ArrayMapJitHelper PHP (#10183, #14977).
 *
 * Null / compile-time string builtins lower via {@see ArrayMapLlvm} (thin standalone AOT;
 * NestedJIT of the helper body segfaults — #23974). Closures still use NestedJIT bridges (#14977).
 * SSOT: {@see \PHPCompiler\ext\standard\array_map}
 * php-src: ext/standard/array.c — php_array_map()
 */
final class ArrayMapRuntime
{
    private const ABI_MAP_NULL = '__array_map__null';

    private const ABI_MAP_NULL_MULTI = '__array_map__null_multi';

    private const ABI_MAP_BUILTIN = '__array_map__builtin';

    private const ABI_MAP_BUILTIN_MULTI = '__array_map__builtin_multi';

    private const ABI_MAP_CLOSURE = '__array_map__closure';

    private const ABI_MAP_CLOSURE_MULTI = '__array_map__closure_multi';

    private const HELPER_PATH = '/ext/standard/ArrayMapJitHelper.php';

    private const CLOSURE_INVOKE_PATH = '/ext/standard/VmClosureInvoke.php';

    private const MAP_NULL = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapNullIdentity';

    private const MAP_NULL_MULTIPLE = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapNullZipMultiple';

    private const MAP_BUILTIN = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapWithBuiltin';

    private const MAP_BUILTIN_MULTIPLE = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapWithBuiltinMultiple';

    private const MAP_CLOSURE = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapWithClosure';

    private const MAP_CLOSURE_MULTIPLE = 'PHPCompiler\\ext\\standard\\ArrayMapJitHelper::mapWithClosureMultiple';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MAP_NULL,
        self::MAP_NULL_MULTIPLE,
        self::MAP_BUILTIN,
        self::MAP_BUILTIN_MULTIPLE,
        self::MAP_CLOSURE,
        self::MAP_CLOSURE_MULTIPLE,
    ];

    public static function mapSingle(Context $context, JITVariable $callback, JITVariable $array): Value
    {
        if (!ArrayMapCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            self::ensureLinked($context);
            $ht = self::argToHashtable($context, $array);

            return self::callMapClosure($context, $ht, $callback);
        }

        $ht = self::argToHashtable($context, $array);
        if (JITVariable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            return ArrayMapLlvm::mapNull($context, $ht);
        }
        $name = $callback->compileTimeString;
        if (null === $name) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }

        return ArrayMapLlvm::mapBuiltin($context, $ht, $name);
    }

    /**
     * @param list<JITVariable> $arrays
     */
    public static function mapNullZipMultiple(Context $context, array $arrays): Value
    {
        self::ensureLinked($context);
        $sources = [];
        foreach ($arrays as $array) {
            $sources[] = self::argToHashtable($context, $array);
        }
        $packed = self::packHashtablePtrArray($context, $sources);

        return self::callMapNullMultiple($context, $packed);
    }

    /**
     * @param list<JITVariable> $arrays
     */
    public static function mapMultipleWithBuiltin(Context $context, array $arrays, string $builtinName): Value
    {
        self::ensureLinked($context);
        $sources = [];
        foreach ($arrays as $array) {
            $sources[] = self::argToHashtable($context, $array);
        }
        $packed = self::packHashtablePtrArray($context, $sources);

        return self::callMapBuiltinMultiple(
            $context,
            $packed,
            $context->builder->load($context->constantStringFromString($builtinName))
        );
    }

    /**
     * @param list<JITVariable> $arrays
     */
    public static function mapMultipleWithClosure(Context $context, JITVariable $callback, array $arrays): Value
    {
        if (!ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        self::ensureLinked($context);
        $sources = [];
        foreach ($arrays as $array) {
            $sources[] = self::argToHashtable($context, $array);
        }
        $packed = self::packHashtablePtrArray($context, $sources);

        return self::callMapClosureMultiple($context, $packed, $callback);
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
        if (self::bridgesComplete($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, self::ABI_MAP_NULL, self::implementMapNullBridge(...));
        self::implementIfMissing($context, self::ABI_MAP_NULL_MULTI, self::implementMapNullMultipleBridge(...));
        self::implementIfMissing($context, self::ABI_MAP_BUILTIN, self::implementMapBuiltinBridge(...));
        self::implementIfMissing($context, self::ABI_MAP_BUILTIN_MULTI, self::implementMapBuiltinMultipleBridge(...));
        self::implementClosureBridges($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementClosureBridges(Context $context): void
    {
        NestedClosureInvokeLlvm::ensureLinked($context);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MAP_CLOSURE,
            'array_map_closure_bridge_entry',
            [$htPtr, $valuePtr],
            $htPtr,
            self::MAP_CLOSURE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14977'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MAP_CLOSURE_MULTI,
            'array_map_closure_multi_bridge_entry',
            [$htPtr, $valuePtr],
            $htPtr,
            self::MAP_CLOSURE_MULTIPLE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14977'
        );
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
                    self::ABI_MAP_NULL => [$htPtr],
                    self::ABI_MAP_NULL_MULTI => [$htPtr],
                    self::ABI_MAP_BUILTIN => [$htPtr, $strPtr],
                    self::ABI_MAP_BUILTIN_MULTI => [$htPtr, $strPtr],
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

    private static function implementMapNullMultipleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_map_null_multi_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MAP_NULL_MULTIPLE),
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

    private static function implementMapBuiltinMultipleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_map_builtin_multi_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MAP_BUILTIN_MULTIPLE),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
    }

    private static function callMapNull(Context $context, Value $ht): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MAP_NULL),
            $ht
        );
    }

    private static function callMapNullMultiple(Context $context, JITVariable $sources): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MAP_NULL_MULTI),
            HashTableHelper::loadHashtablePointer($context, $sources)
        );
    }

    private static function callMapBuiltin(Context $context, Value $ht, Value $namePtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MAP_BUILTIN),
            $ht,
            $namePtr
        );
    }

    private static function callMapBuiltinMultiple(Context $context, JITVariable $sources, Value $namePtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MAP_BUILTIN_MULTI),
            HashTableHelper::loadHashtablePointer($context, $sources),
            $namePtr
        );
    }

    private static function callMapClosure(Context $context, Value $ht, JITVariable $callback): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MAP_CLOSURE),
            $ht,
            JitValueBox::valuePtrFromVariable($context, $callback)
        );
    }

    private static function callMapClosureMultiple(Context $context, JITVariable $sources, JITVariable $callback): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MAP_CLOSURE_MULTI),
            HashTableHelper::loadHashtablePointer($context, $sources),
            JitValueBox::valuePtrFromVariable($context, $callback)
        );
    }

    /**
     * @param list<Value> $sources
     */
    private static function packHashtablePtrArray(Context $context, array $sources): JITVariable
    {
        $vars = [];
        foreach ($sources as $source) {
            $vars[] = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $source);
        }

        return HashTableHelper::packVariables($context, $vars);
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
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14977'
        );
    }

    private static function bridgesComplete(Context $context): bool
    {
        foreach ([
            self::ABI_MAP_NULL,
            self::ABI_MAP_NULL_MULTI,
            self::ABI_MAP_BUILTIN,
            self::ABI_MAP_BUILTIN_MULTI,
            self::ABI_MAP_CLOSURE,
            self::ABI_MAP_CLOSURE_MULTI,
        ] as $name) {
            $probe = $context->module->getNamedFunction($name);
            if (null === $probe || 0 === $probe->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([
            self::ABI_MAP_NULL,
            self::ABI_MAP_NULL_MULTI,
            self::ABI_MAP_BUILTIN,
            self::ABI_MAP_BUILTIN_MULTI,
            self::ABI_MAP_CLOSURE,
            self::ABI_MAP_CLOSURE_MULTI,
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayMapRuntime bridge (#14977)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
