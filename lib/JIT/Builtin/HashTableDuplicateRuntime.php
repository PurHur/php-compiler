<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for hashtable COW duplicate via HashTableJitHelper PHP (#18451).
 *
 * SSOT: {@see \PHPCompiler\VM\HashTable::duplicate()}
 * php-src: Zend/zend_hash.c — zend_array_dup; convert_to_array COW in zend_operators.c
 */
final class HashTableDuplicateRuntime
{
    private const ABI_DUPLICATE = '__hashtable__duplicate';

    private const HELPER_PATH = '/VM/HashTableJitHelper.php';

    private const DUPLICATE_HELPER = 'PHPCompiler\\VM\\HashTableJitHelper::duplicateCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DUPLICATE_HELPER,
    ];

    public static function duplicate(Context $context, Value $srcHt): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_DUPLICATE),
            $srcHt
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
        $probe = $context->module->getNamedFunction(self::ABI_DUPLICATE);
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
            self::ABI_DUPLICATE,
            'hashtable_duplicate_bridge_entry',
            [$htPtr],
            $htPtr,
            self::DUPLICATE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18451'
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
        $fn = $context->module->getNamedFunction(self::ABI_DUPLICATE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_DUPLICATE.' missing after HashTableDuplicateRuntime bridge (#18451)');
        }
        $context->registerFunction(self::ABI_DUPLICATE, $fn);
    }
}
