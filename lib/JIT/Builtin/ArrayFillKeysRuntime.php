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
 * JIT/AOT link for array_fill_keys() via ArrayFillKeysJitHelper PHP (#12487).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::fillKeys()}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::fillKeys()}
 * php-src: ext/standard/array.c — php_array_fill_keys()
 */
final class ArrayFillKeysRuntime
{
    private const ABI_FILL_KEYS = '__array_fill_keys__copy';

    private const HELPER_PATH = '/ext/standard/ArrayFillKeysJitHelper.php';

    private const FILL_KEYS_HELPER = 'PHPCompiler\\ext\\standard\\ArrayFillKeysJitHelper::fillKeysCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FILL_KEYS_HELPER,
    ];

    public static function fillKeys(Context $context, JITVariable $keys, JITVariable $value): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($keys->type)) {
            return ArrayBuiltinHelper::fillKeys($context, $keys, $value);
        }

        self::ensureLinked($context);
        $keysHt = ArrayBuiltinHelper::loadHashTable($context, $keys);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FILL_KEYS),
            $keysHt,
            $valuePtr
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

        $probe = $context->module->getNamedFunction(self::ABI_FILL_KEYS);
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
            self::ABI_FILL_KEYS,
            'array_fill_keys_bridge_entry',
            [$htPtr, $valuePtr],
            $htPtr,
            self::FILL_KEYS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12487'
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
        $fn = $context->module->getNamedFunction(self::ABI_FILL_KEYS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FILL_KEYS.' missing after ArrayFillKeysRuntime bridge (#12487)');
        }
        $context->registerFunction(self::ABI_FILL_KEYS, $fn);
    }
}
