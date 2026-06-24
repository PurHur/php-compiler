<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for Closure::bind/bindTo guards via ClosureBindJitHelper PHP (#10109).
 *
 * SSOT: {@see \PHPCompiler\VM\ClosureSupport}, {@see \PHPCompiler\VM\ClosureBindJitHelper}
 */
final class ClosureBindRuntime
{
    private const HELPER_PATH = '/VM/ClosureBindJitHelper.php';

    private const VALUE_BOX_NULLABLE_OBJECT_KIND = 'PHPCompiler\\VM\\ClosureBindJitHelper::valueBoxKindForNullableObject';

    private const VALUE_BOX_NULLABLE_OBJECT_OR_STRING_KIND = 'PHPCompiler\\VM\\ClosureBindJitHelper::valueBoxKindForNullableObjectOrString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_NULLABLE_OBJECT_KIND,
        self::VALUE_BOX_NULLABLE_OBJECT_OR_STRING_KIND,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (
            null !== self::probeLinked($context, '__closure_bind__valueBoxKindForNullableObject')
            && null !== self::probeLinked($context, '__closure_bind__valueBoxKindForNullableObjectOrString')
        ) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            '__closure_bind__valueBoxKindForNullableObject',
            'closure_bind_nullable_object_kind_entry',
            [$i8],
            $i32,
            self::VALUE_BOX_NULLABLE_OBJECT_KIND,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10109'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__closure_bind__valueBoxKindForNullableObjectOrString',
            'closure_bind_nullable_object_or_string_kind_entry',
            [$i8],
            $i32,
            self::VALUE_BOX_NULLABLE_OBJECT_OR_STRING_KIND,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10109'
        );
        $context->builder->clearInsertionPosition();
    }

    public static function callValueBoxKindForNullableObject(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__closure_bind__valueBoxKindForNullableObject');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    public static function callValueBoxKindForNullableObjectOrString(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__closure_bind__valueBoxKindForNullableObjectOrString');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    private static function probeLinked(Context $context, string $abiName): ?\PHPLLVM\Value\Function_
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return $probe;
        }

        return null;
    }
}
