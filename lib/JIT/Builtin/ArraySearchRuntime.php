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
 * JIT/AOT link for array_search() via ArraySearchJitHelper PHP (#12514).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::arraySearch()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::searchKey()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_search)
 */
final class ArraySearchRuntime
{
    private const ABI_SEARCH = '__array_search__key';

    private const HELPER_PATH = '/ext/standard/ArraySearchJitHelper.php';

    private const SEARCH_HELPER = 'PHPCompiler\\ext\\standard\\ArraySearchJitHelper::searchKey';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SEARCH_HELPER,
    ];

    public static function search(
        Context $context,
        JITVariable $needle,
        JITVariable $haystack,
        Value $strict
    ): Value {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($haystack->type)) {
            return ArrayBuiltinHelper::arraySearch($context, $needle, $haystack, $strict);
        }

        self::ensureLinked($context);
        $needlePtr = JitValueBox::valuePtrFromVariable($context, $needle);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $haystack);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SEARCH),
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

        $probe = $context->module->getNamedFunction(self::ABI_SEARCH);
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
            self::ABI_SEARCH,
            'array_search_bridge_entry',
            [$valuePtr, $htPtr, $i1],
            $valuePtr,
            self::SEARCH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12514'
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
        $fn = $context->module->getNamedFunction(self::ABI_SEARCH);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_SEARCH.' missing after ArraySearchRuntime bridge (#12514)');
        }
        $context->registerFunction(self::ABI_SEARCH, $fn);
    }
}
