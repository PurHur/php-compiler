<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_keys() via ArrayKeysJitHelper PHP (#12340).
 *
 * Standalone AOT and native literal arrays materialize to hashtable then route through PHP (#14387, #18287).
 * SSOT: {@see \PHPCompiler\VM\HashTable::keysCopy()}
 * php-src: ext/standard/array.c — php_array_keys()
 */
final class ArrayKeysRuntime
{
    private const ABI_KEYS = '__array_keys__copy';

    private const ABI_KEYS_FILTERED = '__array_keys__matching';

    private const HELPER_PATH = '/ext/standard/ArrayKeysJitHelper.php';

    private const KEYS_HELPER = 'PHPCompiler\\ext\\standard\\ArrayKeysJitHelper::keysCopy';

    private const KEYS_MATCHING_HELPER = 'PHPCompiler\\ext\\standard\\ArrayKeysJitHelper::keysMatching';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::KEYS_HELPER,
        self::KEYS_MATCHING_HELPER,
    ];

    public static function keys(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_KEYS),
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function keysFiltered(
        Context $context,
        JITVariable $array,
        JITVariable $searchValue,
        Value $strict
    ): Value {
        self::ensureFilteredLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_KEYS_FILTERED),
            ArrayBuiltinHelper::loadHashTable($context, $array),
            JitValueBox::valuePtrFromVariable($context, $searchValue),
            $strict
        );
    }

    public static function ensureLinked(Context $context): void
    {
        self::implementKeys($context);
    }

    public static function ensureFilteredLinked(Context $context): void
    {
        self::implementKeys($context);
        self::implementKeysFiltered($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implementKeys($context);
        self::implementKeysFiltered($context);
    }

    private static function implementKeys(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_KEYS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinked($context, self::ABI_KEYS);

            return;
        }

        $savedBlock = self::saveInsertBlock($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_KEYS,
            'array_keys_bridge_entry',
            [$htPtr],
            $htPtr,
            self::KEYS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12340'
        );
        self::registerLinked($context, self::ABI_KEYS);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function implementKeysFiltered(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_KEYS_FILTERED);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinked($context, self::ABI_KEYS_FILTERED);

            return;
        }

        $savedBlock = self::saveInsertBlock($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_KEYS_FILTERED,
            'array_keys_matching_bridge_entry',
            [$htPtr, $valuePtr, $i1],
            $htPtr,
            self::KEYS_MATCHING_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12340'
        );
        self::registerLinked($context, self::ABI_KEYS_FILTERED);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function registerLinked(Context $context, string $abiName): void
    {
        $fn = $context->module->getNamedFunction($abiName);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException($abiName.' missing after ArrayKeysRuntime bridge (#12340)');
        }
        $context->registerFunction($abiName, $fn);
    }

    private static function saveInsertBlock(Context $context): mixed
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, mixed $savedBlock): void
    {
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
