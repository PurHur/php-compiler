<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmArrayAccess;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * JIT trampoline for ArrayAccess $obj[$key] (Zend read_dimension / write_dimension, #3331, #4012, #10246).
 *
 * SSOT: {@see \PHPCompiler\VM\VmArrayAccess}
 */
final class ArrayAccessHelper
{
    public static function containerImplementsArrayAccess(
        Context $context,
        Variable $container,
        ?Operand $containerOp
    ): bool {
        return VmArrayAccess::containerImplementsArrayAccess($context, $container, $containerOp);
    }

    public static function tryCompileDimFetch(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $containerOp,
        bool $forWrite
    ): ?Variable {
        return VmArrayAccess::tryCompileDimFetch($context, $container, $dim, $containerOp, $forWrite);
    }

    public static function tryCompileOffsetIsSet(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $containerOp
    ): ?Value {
        return VmArrayAccess::tryCompileOffsetIsSet($context, $container, $dim, $containerOp);
    }

    public static function tryCompileOffsetUnset(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $containerOp
    ): bool {
        return VmArrayAccess::tryCompileOffsetUnset($context, $container, $dim, $containerOp);
    }

    public static function isKnownNonArrayAccessObject(
        Context $context,
        Variable $container,
        ?Operand $containerOp
    ): bool {
        return VmArrayAccess::isKnownNonArrayAccessObject($context, $container, $containerOp);
    }

    public static function emitIllegalOffset(Context $context): void
    {
        VmArrayAccess::emitIllegalOffset($context);
    }

    public static function emitIndirectModifyNotice(Context $context, string $className): void
    {
        VmArrayAccess::emitIndirectModifyNotice($context, $className);
    }

    public static function assignWritableOffset(Context $context, Variable $lvalue, Variable $value): void
    {
        VmArrayAccess::assignWritableOffset($context, $lvalue, $value);
    }
}
