<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for shuffle() via ShuffleJitHelper PHP (#12762).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::shufflePacked()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::shufflePacked()}
 * php-src: ext/standard/array.c — php_shuffle
 */
final class ShuffleRuntime
{
    private const ABI_SHUFFLE = '__shuffle__packed';

    private const HELPER_PATH = '/ext/standard/ShuffleJitHelper.php';

    private const SHUFFLE_HELPER = 'PHPCompiler\\ext\\standard\\ShuffleJitHelper::shufflePacked';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SHUFFLE_HELPER,
    ];

    public static function shufflePacked(Context $context, JITVariable $array): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::shufflePacked($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_SHUFFLE), $ht);
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

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SHUFFLE,
            'shuffle_packed_bridge_entry',
            [$htPtr],
            $void,
            self::SHUFFLE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12762'
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
        $fn = $context->module->getNamedFunction(self::ABI_SHUFFLE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_SHUFFLE.' missing after ShuffleRuntime bridge (#12762)');
        }
        $context->registerFunction(self::ABI_SHUFFLE, $fn);
    }
}
