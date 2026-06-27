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
 * JIT/AOT link for array_product() via ArrayProductJitHelper PHP (#12591).
 *
 * Standalone AOT keeps LLVM in {@see ArrayBuiltinHelper::arrayProduct()}.
 * SSOT: {@see \PHPCompiler\ext\standard\ArrayProductJitHelper}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_product)
 */
final class ArrayProductRuntime
{
    private const ABI_PRODUCT = '__array_product__fold';

    private const HELPER_PATH = '/ext/standard/ArrayProductJitHelper.php';

    private const PRODUCT_HELPER = 'PHPCompiler\\ext\\standard\\ArrayProductJitHelper::product';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PRODUCT_HELPER,
    ];

    public static function product(Context $context, JITVariable $array): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::arrayProduct($context, $array);
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PRODUCT),
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

        $probe = $context->module->getNamedFunction(self::ABI_PRODUCT);
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
            self::ABI_PRODUCT,
            'array_product_bridge_entry',
            [$htPtr],
            $valuePtr,
            self::PRODUCT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12591'
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
        $fn = $context->module->getNamedFunction(self::ABI_PRODUCT);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_PRODUCT.' missing after ArrayProductRuntime bridge (#12591)');
        }
        $context->registerFunction(self::ABI_PRODUCT, $fn);
    }
}
