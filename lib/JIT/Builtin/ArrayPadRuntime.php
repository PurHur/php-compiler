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
 * JIT/AOT link for array_pad() via ArrayPadJitHelper PHP (#12476, #18121).
 *
 * Standalone AOT and native literal arrays materialize to hashtable then route through PHP (#18121).
 * SSOT: {@see \PHPCompiler\VM\HashTable::padCopy()}
 * php-src: ext/standard/array.c — php_array_pad()
 */
final class ArrayPadRuntime
{
    private const ABI_PAD = '__array_pad__copy';

    private const ABI_PAD_TYPED = '__array_pad__copy_typed';

    private const HELPER_PATH = '/ext/standard/ArrayPadJitHelper.php';

    private const PAD_LEGACY_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPadJitHelper::padCopyLegacy';

    private const PAD_TYPED_HELPER = 'PHPCompiler\\ext\\standard\\ArrayPadJitHelper::padCopyTyped';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PAD_LEGACY_HELPER,
        self::PAD_TYPED_HELPER,
    ];

    public static function pad(
        Context $context,
        JITVariable $array,
        Value $length,
        JITVariable $value
    ): Value {
        return self::padWithType($context, $array, $length, $value, null);
    }

    public static function padWithType(
        Context $context,
        JITVariable $array,
        Value $length,
        JITVariable $value,
        ?Value $padType
    ): Value {
        self::ensureLinked($context);
        $ht = self::argToHashtable($context, $array);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);
        if (null === $padType) {
            return $context->builder->call(
                $context->lookupFunction(self::ABI_PAD),
                $ht,
                $length,
                $valuePtr
            );
        }

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PAD_TYPED),
            $ht,
            $length,
            $valuePtr,
            $padType
        );
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
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
        $probePad = $context->module->getNamedFunction(self::ABI_PAD);
        $probeTyped = $context->module->getNamedFunction(self::ABI_PAD_TYPED);
        if (null !== $probePad && $probePad->countBasicBlocks() > 0
            && null !== $probeTyped && $probeTyped->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PAD,
            'array_pad_bridge_entry',
            [$htPtr, $i64, $valuePtr],
            $htPtr,
            self::PAD_LEGACY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12476'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PAD_TYPED,
            'array_pad_typed_bridge_entry',
            [$htPtr, $i64, $valuePtr, $i64],
            $htPtr,
            self::PAD_TYPED_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14993'
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
        foreach ([self::ABI_PAD, self::ABI_PAD_TYPED] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after ArrayPadRuntime bridge (#12476)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
