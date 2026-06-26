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
 * JIT/AOT link for array_flip() via ArrayFlipJitHelper PHP (#12329).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::buildFlipArray()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray}
 * php-src: ext/standard/array.c — php_array_flip()
 */
final class ArrayFlipRuntime
{
    private const ABI_FLIP = '__array_flip__flip';

    private const HELPER_PATH = '/ext/standard/ArrayFlipJitHelper.php';

    private const FLIP_HELPER = 'PHPCompiler\\ext\\standard\\ArrayFlipJitHelper::flip';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FLIP_HELPER,
    ];

    public static function flip(Context $context, JITVariable $array): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::buildFlipArray($context, $array);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FLIP),
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

        $probe = $context->module->getNamedFunction(self::ABI_FLIP);
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FLIP,
            'array_flip_bridge_entry',
            [$htPtr],
            $htPtr,
            self::FLIP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12329'
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
        $fn = $context->module->getNamedFunction(self::ABI_FLIP);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FLIP.' missing after ArrayFlipRuntime bridge (#12329)');
        }
        $context->registerFunction(self::ABI_FLIP, $fn);
    }
}
