<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_pop() via ArrayPopJitHelper PHP (#12647).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::popLast()}.
 * SSOT: {@see \PHPCompiler\ext\standard\array_pop}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_pop)
 */
final class ArrayPopRuntime
{
    private const ABI_POP = '__array_pop__last';

    private const HELPER_PATH = '/ext/standard/ArrayPopJitHelper.php';

    private const POP_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPopJitHelper::pop';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::POP_HELPER,
    ];

    public static function pop(Context $context, JITVariable $array): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::popLast($context, $array);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_POP),
            $ht
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

        $probe = $context->module->getNamedFunction(self::ABI_POP);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_POP,
            'array_pop_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::POP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12647'
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
        $fn = $context->module->getNamedFunction(self::ABI_POP);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_POP.' missing after ArrayPopRuntime bridge (#12647)');
        }
        $context->registerFunction(self::ABI_POP, $fn);
    }
}
