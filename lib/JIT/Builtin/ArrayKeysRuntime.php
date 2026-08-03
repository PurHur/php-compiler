<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableKeysLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_keys() (#12340, #27211).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayKeysJitHelper} returned
 * empty hashtables because NestedVmHashTableMethodLlvm skipped keysCopy (#20533).
 * Call-site LLVM via {@see HashTableKeysLlvm} (peer ArrayReverseRuntime / #27067).
 *
 * Filtered form (`search_value`) still NestedJIT-compiles ArrayKeysJitHelper::keysMatching.
 *
 * VM SSOT: {@see \PHPCompiler\VM\HashTable::keysCopy()} / {@see keysMatchingCopy()}
 * php-src: ext/standard/array.c — php_array_keys()
 */
final class ArrayKeysRuntime
{
    private const ABI_KEYS = '__array_keys__copy';

    private const ABI_KEYS_FILTERED = '__array_keys__matching';

    private const HELPER_PATH = '/ext/standard/ArrayKeysJitHelper.php';

    private const KEYS_MATCHING_HELPER = 'PHPCompiler\\ext\\standard\\ArrayKeysJitHelper::keysMatching';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
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

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitKeysBridge($context);
        self::registerLinked($context, self::ABI_KEYS);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitKeysBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $probe = $context->module->getNamedFunction(self::ABI_KEYS);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_KEYS,
                $context->context->functionType($htPtr, false, $htPtr)
            );

        $entry = $fn->appendBasicBlock('array_keys_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $keys = HashTableKeysLlvm::keys($context, $src);
        $context->builder->returnValue($keys);
        $context->registerFunction(self::ABI_KEYS, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementKeysFiltered(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_KEYS_FILTERED);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinked($context, self::ABI_KEYS_FILTERED);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

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
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinked(Context $context, string $abiName): void
    {
        $fn = $context->module->getNamedFunction($abiName);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException($abiName.' missing after ArrayKeysRuntime bridge (#27211)');
        }
        $context->registerFunction($abiName, $fn);
    }
}
