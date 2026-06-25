<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** JIT/AOT link for list/spread unpack guards via ListUnpackJitHelper PHP (#10221, #10266). */
final class ListUnpackRuntime
{
    private static bool $implementing = false;

    private const HELPER_PATH = '/VM/ListUnpackJitHelper.php';

    private const H_ARRAY = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsArray';

    private const H_STRING = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsString';

    private const H_UNPACK = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsListDestructUnpackable';

    private const COMPILED = [self::H_ARRAY, self::H_STRING, self::H_UNPACK];

    private const ABI_ARRAY = '__list_unpack__valueBoxIsArray';

    private const ABI_STRING = '__list_unpack__valueBoxIsString';

    private const ABI_UNPACK = '__list_unpack__valueBoxIsListDestructUnpackable';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (self::$implementing || self::bridgesReady($context)) {
            return;
        }
        self::$implementing = true;
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        try {
            $i8 = $context->getTypeFromString('int8');
            $i1 = $context->getTypeFromString('int1');
            JitVmHelperLink::ensureBridge($context, self::ABI_ARRAY, 'list_unpack_is_array', [$i8], $i1, self::H_ARRAY, self::HELPER_PATH, self::COMPILED, '#10266');
            JitVmHelperLink::ensureBridge($context, self::ABI_STRING, 'list_unpack_is_string', [$i8], $i1, self::H_STRING, self::HELPER_PATH, self::COMPILED, '#10266');
            JitVmHelperLink::ensureBridge($context, self::ABI_UNPACK, 'list_unpack_is_unpackable', [$i8, $i1], $i1, self::H_UNPACK, self::HELPER_PATH, self::COMPILED, '#10266');
        } finally {
            if (null !== $savedBlock) {
                $context->builder->positionAtEnd($savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
            self::$implementing = false;
        }
    }

    public static function callValueBoxIsArray(Context $context, Value $typeByte): Value
    {
        return self::callBridge($context, self::ABI_ARRAY, $typeByte);
    }

    public static function callValueBoxIsString(Context $context, Value $typeByte): Value
    {
        return self::callBridge($context, self::ABI_STRING, $typeByte);
    }

    public static function callValueBoxIsListDestructUnpackable(
        Context $context,
        Value $typeByte,
        Value $implementsArrayAccess
    ): Value {
        self::ensureLinked($context);
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->call(
            $context->lookupFunction(self::ABI_UNPACK),
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($implementsArrayAccess, $i1)
        );
    }

    public static function loadValueBoxTypeByte(Context $context, Variable $var): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);

        return $context->builder->load(
            $context->builder->structGep($valuePtr, $context->structFieldMap['__value__']['type'])
        );
    }

    private static function callBridge(Context $context, string $abi, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $context->lookupFunction($abi),
            $context->builder->trunc($typeByte, $i8)
        );
    }

    private static function bridgesReady(Context $context): bool
    {
        foreach ([self::ABI_ARRAY, self::ABI_STRING, self::ABI_UNPACK] as $abi) {
            try {
                $context->lookupFunction($abi);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }
}
