<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array union (`+`) via HashTableJitHelper PHP.
 *
 * Restores the bridge deleted as “dead” LLVM in #18409 while {@see \PHPCompiler\JIT\Helper}
 * still called {@see \PHPCompiler\JIT\ArrayBuiltinHelper::arrayUnion} (#10533 Pillar 1 /
 * bootstrap TYPE_PLUS HASHTABLE+VALUE).
 *
 * SSOT: {@see \PHPCompiler\VM\HashTable::unionCopy()}
 * php-src: Zend/zend_operators.c — add_function array union; Zend/zend_hash.c merge
 */
final class HashTableUnionRuntime
{
    private const ABI_UNION = '__hashtable__union';

    private const HELPER_PATH = '/VM/HashTableJitHelper.php';

    private const UNION_HELPER = 'PHPCompiler\\VM\\HashTableJitHelper::unionCopy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::UNION_HELPER,
    ];

    public static function union(Context $context, Value $leftHt, Value $rightHt): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_UNION),
            $leftHt,
            $rightHt
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
        $probe = $context->module->getNamedFunction(self::ABI_UNION);
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
            self::ABI_UNION,
            'hashtable_union_bridge_entry',
            [$htPtr, $htPtr],
            $htPtr,
            self::UNION_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10533'
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
        $fn = $context->module->getNamedFunction(self::ABI_UNION);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_UNION.' missing after HashTableUnionRuntime bridge (#10533)');
        }
        $context->registerFunction(self::ABI_UNION, $fn);
    }
}
