<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_key_exists()/key_exists() via ArrayKeyExistsJitHelper PHP (#13735, #14545).
 *
 * Standalone + embed compile {@see ArrayKeyExistsJitHelper} via JitVmHelperLink; native literal arrays keep LLVM in {@see nativeArrayKeyExists()}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_key_exists)
 */
final class ArrayKeyExistsRuntime
{
    private const ABI_KEY_EXISTS = '__array_key_exists__has_key';

    private const HELPER_PATH = '/ext/standard/ArrayKeyExistsJitHelper.php';

    private const KEY_EXISTS_HELPER = 'PHPCompiler\\ext\\standard\\ArrayKeyExistsJitHelper::keyExists';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::KEY_EXISTS_HELPER,
    ];

    public static function keyExists(
        Context $context,
        JITVariable $key,
        JITVariable $array,
        string $function
    ): Value {
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            return self::nativeArrayKeyExists($context, $key, $array, $function);
        }
        if (JITVariable::TYPE_OBJECT === $key->type) {
            HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type');

            return $context->constantFromInteger(0, 'int1');
        }

        self::ensureLinked($context);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $key);
        $ht = JITVariable::TYPE_HASHTABLE === $array->type
            ? $context->helper->loadValue($array)
            : ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_KEY_EXISTS),
            $keyPtr,
            $ht
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
        $probe = $context->module->getNamedFunction(self::ABI_KEY_EXISTS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_KEY_EXISTS,
            'array_key_exists_bridge_entry',
            [$valuePtr, $htPtr],
            $i1,
            self::KEY_EXISTS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14545'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function nativeArrayKeyExists(
        Context $context,
        JITVariable $key,
        JITVariable $array,
        string $function
    ): Value {
        if (JITVariable::TYPE_NULL === $key->type
            || JITVariable::TYPE_STRING === $key->type
            || JITVariable::TYPE_VALUE === $key->type) {
            return $context->constantFromInteger(0, 'int1');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $key->type) {
            throw new \LogicException(
                $function.'() on native arrays only supports integer keys in this compiler build'
            );
        }
        $index = JitLongArg::lower($context, $key, $function.'() key');
        $size = $context->constantFromInteger($array->nextFreeElement, 'int32');
        $i32 = $context->getTypeFromString('int32');
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

        return $context->builder->and($inRange, $nonNeg);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_KEY_EXISTS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_KEY_EXISTS.' missing after ArrayKeyExistsRuntime bridge (#14545)');
        }
        $context->registerFunction(self::ABI_KEY_EXISTS, $fn);
    }
}
