<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayUserSetOpsKeyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_udiff()/array_uintersect()/array_diff_ukey()/array_intersect_ukey() (#18515, #27228).
 *
 * Value comparators: NestedJIT {@see \PHPCompiler\ext\standard\ArrayUserSetOpsJitHelper}.
 * Key comparators: pure LLVM {@see ArrayUserSetOpsKeyLlvm} — NestedJIT key filters abort under thin AOT.
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmArrayUserSetOps}
 * php-src: ext/standard/array.c
 */
final class ArrayUserSetOpsRuntime
{
    private const ABI_UDIFF_CLOSURE = '__array_udiff__closure';

    private const ABI_UINTERSECT_CLOSURE = '__array_uintersect__closure';

    private const HELPER_PATH = '/ext/standard/ArrayUserSetOpsJitHelper.php';

    private const UDIFF_CLOSURE = 'PHPCompiler\\ext\\standard\\ArrayUserSetOpsJitHelper::diffByValueWithClosure';

    private const UINTERSECT_CLOSURE = 'PHPCompiler\\ext\\standard\\ArrayUserSetOpsJitHelper::intersectByValueWithClosure';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::UDIFF_CLOSURE,
        self::UINTERSECT_CLOSURE,
    ];

    public static function diffByValue(
        Context $context,
        bool $intersect,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        self::requireClosureCallback($context, $callback);
        self::ensureLinked($context);
        $src = self::argToHashtable($context, $first);
        $packed = self::packOtherHashTables($context, $others);

        return $context->builder->call(
            $context->lookupFunction($intersect ? self::ABI_UINTERSECT_CLOSURE : self::ABI_UDIFF_CLOSURE),
            $src,
            HashTableHelper::loadHashtablePointer($context, $packed),
            JitValueBox::valuePtrFromVariable($context, $callback)
        );
    }

    public static function diffByKey(
        Context $context,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        return self::filterByKey($context, false, $callback, $first, ...$others);
    }

    public static function intersectByKey(
        Context $context,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        return self::filterByKey($context, true, $callback, $first, ...$others);
    }

    private static function filterByKey(
        Context $context,
        bool $intersect,
        JITVariable $callback,
        JITVariable $first,
        JITVariable ...$others
    ): Value {
        self::requireClosureCallback($context, $callback);
        $src = self::argToHashtable($context, $first);
        $packed = self::packOtherHashTables($context, $others);

        return ArrayUserSetOpsKeyLlvm::filterByKey(
            $context,
            $intersect,
            $src,
            HashTableHelper::loadHashtablePointer($context, $packed)
        );
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

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        foreach ([
            [self::ABI_UDIFF_CLOSURE, 'array_udiff_closure_bridge_entry', self::UDIFF_CLOSURE],
            [self::ABI_UINTERSECT_CLOSURE, 'array_uintersect_closure_bridge_entry', self::UINTERSECT_CLOSURE],
        ] as [$abi, $entry, $helper]) {
            JitVmHelperLink::ensureBridge(
                $context,
                $abi,
                $entry,
                [$htPtr, $htPtr, $valuePtr],
                $htPtr,
                $helper,
                self::HELPER_PATH,
                self::COMPILED_HELPERS,
                '#18515'
            );
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function requireClosureCallback(Context $context, JITVariable $callback): void
    {
        if (!UsortCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
    }

    /**
     * @param list<JITVariable> $others
     */
    private static function packOtherHashTables(Context $context, array $others): JITVariable
    {
        $vars = [];
        foreach ($others as $other) {
            $vars[] = new JITVariable(
                $context,
                JITVariable::TYPE_HASHTABLE,
                JITVariable::KIND_VALUE,
                self::argToHashtable($context, $other)
            );
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

    private static function bridgesComplete(Context $context): bool
    {
        foreach ([
            self::ABI_UDIFF_CLOSURE,
            self::ABI_UINTERSECT_CLOSURE,
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
            self::ABI_UDIFF_CLOSURE,
            self::ABI_UINTERSECT_CLOSURE,
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayUserSetOpsRuntime bridge (#18515)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
