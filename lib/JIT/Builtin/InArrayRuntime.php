<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for in_array() via InArrayJitHelper PHP (#12503).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::inArray()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::contains()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(in_array)
 */
final class InArrayRuntime
{
    private const ABI_CONTAINS = '__in_array__contains';

    private const HELPER_PATH = '/ext/standard/InArrayJitHelper.php';

    private const CONTAINS_HELPER = 'PHPCompiler\\ext\\standard\\InArrayJitHelper::contains';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONTAINS_HELPER,
    ];

    public static function inArray(
        Context $context,
        JITVariable $needle,
        JITVariable $haystack,
        Value $strict
    ): Value {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($haystack->type)) {
            return ArrayBuiltinHelper::inArray($context, $needle, $haystack, $strict);
        }

        self::ensureLinked($context);
        $needlePtr = JitValueBox::valuePtrFromVariable($context, $needle);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $haystack);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CONTAINS),
            $needlePtr,
            $ht,
            $strict
        );
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

        $probe = $context->module->getNamedFunction(self::ABI_CONTAINS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CONTAINS,
            'in_array_bridge_entry',
            [$valuePtr, $htPtr, $i1],
            $i1,
            self::CONTAINS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12503'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_CONTAINS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_CONTAINS.' missing after InArrayRuntime bridge (#12503)');
        }
        $context->registerFunction(self::ABI_CONTAINS, $fn);
    }
}
