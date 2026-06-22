<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for list/spread unpack guards via ListUnpackJitHelper PHP (#10221, #10266).
 *
 * SSOT: {@see \PHPCompiler\VM\ListUnpackJitHelper}
 */
final class ListUnpackRuntime
{
    private const HELPER_PATH = '/VM/ListUnpackJitHelper.php';

    private const VALUE_BOX_IS_ARRAY = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsArray';

    private const VALUE_BOX_IS_STRING = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsString';

    private const VALUE_BOX_IS_UNPACKABLE = 'PHPCompiler\\VM\\ListUnpackJitHelper::valueBoxIsListDestructUnpackable';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_IS_ARRAY,
        self::VALUE_BOX_IS_STRING,
        self::VALUE_BOX_IS_UNPACKABLE,
    ];

    private const ABI_IS_ARRAY = '__list_unpack__valueBoxIsArray';

    private const ABI_IS_STRING = '__list_unpack__valueBoxIsString';

    private const ABI_IS_UNPACKABLE = '__list_unpack__valueBoxIsListDestructUnpackable';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (self::bridgesReady($context)) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IS_ARRAY,
            'list_unpack_value_box_is_array_entry',
            [$i8],
            $i1,
            self::VALUE_BOX_IS_ARRAY,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10266'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IS_STRING,
            'list_unpack_value_box_is_string_entry',
            [$i8],
            $i1,
            self::VALUE_BOX_IS_STRING,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10266'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IS_UNPACKABLE,
            'list_unpack_value_box_is_unpackable_entry',
            [$i8, $i1],
            $i1,
            self::VALUE_BOX_IS_UNPACKABLE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10266'
        );
        $context->builder->clearInsertionPosition();
    }

    public static function callValueBoxIsArray(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_IS_ARRAY);
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    public static function callValueBoxIsString(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_IS_STRING);
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    public static function callValueBoxIsListDestructUnpackable(
        Context $context,
        Value $typeByte,
        Value $implementsArrayAccess
    ): Value {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_IS_UNPACKABLE);
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($implementsArrayAccess, $i1)
        );
    }

    public static function loadValueBoxTypeByte(Context $context, Variable $var): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);

        return $context->builder->load(
            $context->builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
    }

    private static function bridgesReady(Context $context): bool
    {
        foreach ([self::ABI_IS_ARRAY, self::ABI_IS_STRING, self::ABI_IS_UNPACKABLE] as $abiName) {
            $probe = $context->module->getNamedFunction($abiName);
            if (null === $probe || 0 === $probe->countBasicBlocks()) {
                return false;
            }
            $context->registerFunction($abiName, $probe);
        }

        return true;
    }
}
