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
 * JIT/AOT link for array_replace_key() via ArrayReplaceKeyJitHelper PHP (#12488).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::arrayReplaceKey()}.
 * SSOT: {@see \PHPCompiler\VM\HashTable::replaceKeyCopy()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_key) (PHP 8.4+)
 */
final class ArrayReplaceKeyRuntime
{
    private const ABI_REPLACE_KEY = '__array_replace_key__copy';

    private const HELPER_PATH = '/ext/standard/ArrayReplaceKeyJitHelper.php';

    private const REPLACE_KEY_HELPER = 'PHPCompiler\\ext\\standard\\ArrayReplaceKeyJitHelper::replaceKeyCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REPLACE_KEY_HELPER,
    ];

    public static function replaceKey(Context $context, JITVariable $base, JITVariable $replacements): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($base->type)
            || ArrayBuiltinHelper::isNativeArray($replacements->type)) {
            return ArrayBuiltinHelper::arrayReplaceKey($context, $base, $replacements);
        }

        self::ensureLinked($context);
        $baseHt = ArrayBuiltinHelper::loadHashTable($context, $base);
        $replHt = ArrayBuiltinHelper::loadHashTable($context, $replacements);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_REPLACE_KEY),
            $baseHt,
            $replHt
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

        $probe = $context->module->getNamedFunction(self::ABI_REPLACE_KEY);
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
            self::ABI_REPLACE_KEY,
            'array_replace_key_bridge_entry',
            [$htPtr, $htPtr],
            $htPtr,
            self::REPLACE_KEY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12488'
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
        $fn = $context->module->getNamedFunction(self::ABI_REPLACE_KEY);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_REPLACE_KEY.' missing after ArrayReplaceKeyRuntime bridge (#12488)');
        }
        $context->registerFunction(self::ABI_REPLACE_KEY, $fn);
    }
}
