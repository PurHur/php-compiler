<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Shared unset() semantic guards for VM + JIT lowering (#10238).
 *
 * php-src: Zend/zend_execute.c ZEND_UNSET_DIM, property unset + hooks
 */
final class VmUnset
{
    public const ERROR_NON_ARRAY = 'Cannot unset offset in a non-array variable';

    public const ERROR_STRING_OFFSET = 'Cannot unset string offsets';

    /**
     * Zend default unset_dimension / write_dimension on non-ArrayAccess objects
     * (zend_object_handlers.c; DOMNodeList/DOMNamedNodeMap keep read/has only — #23304, re-#20311).
     */
    public static function cannotUseObjectAsArrayMessage(string $className): string
    {
        return 'Cannot use object of type '.$className.' as array';
    }

    /**
     * ZEND_UNSET_DIM on null/undef — silent no-op (zend_vm_def.h; #30099).
     *
     * Zend: type <= IS_FALSE and not IS_FALSE falls through without convert or Error.
     */
    public static function isNullOrUndefUnsetDimNoop(Variable $container): bool
    {
        $resolved = $container->resolveIndirect();

        return Variable::TYPE_NULL === $resolved->type
            || Variable::TYPE_UNDEFINED === $resolved->type;
    }

    /**
     * ZEND_UNSET_DIM on false — E_DEPRECATED only; leaves false (zend_vm_def.h; #30099).
     *
     * Unlike FETCH_DIM_W, unset does **not** promote false→array.
     */
    public static function isFalseUnsetDimDeprecated(Variable $container): bool
    {
        return TypeCheck::isFalseContainerForDimAutovivify($container);
    }

    /**
     * Scalar JIT containers that may need unset-dim handling (Zend zend_unset_dim).
     *
     * Null/false are handled as no-op / Deprecated before {@see scalarUnsetDimErrorMessage()}.
     */
    public static function isScalarJitContainer(JitVariable $container): bool
    {
        return JitVariable::TYPE_STRING === $container->type
            || JitVariable::TYPE_NULL === $container->type
            || JitVariable::TYPE_NATIVE_BOOL === $container->type
            || JitVariable::TYPE_NATIVE_LONG === $container->type
            || JitVariable::TYPE_NATIVE_DOUBLE === $container->type;
    }

    public static function scalarUnsetDimErrorMessage(int $containerType): string
    {
        return JitVariable::TYPE_STRING === $containerType
            ? self::ERROR_STRING_OFFSET
            : self::ERROR_NON_ARRAY;
    }

    /**
     * Declaring class for property unset when operand type is not static (#1224).
     */
    public static function resolveDeclaringClass(
        ?string $operandUserType,
        ?string $blockClassName,
        string $scopeClassName
    ): string {
        $declaringClass = $operandUserType;
        if (null === $declaringClass || '' === $declaringClass) {
            $declaringClass = $blockClassName;
        }
        if (null === $declaringClass || '' === $declaringClass) {
            $declaringClass = '' !== $scopeClassName ? $scopeClassName : 'object';
        }

        return $declaringClass;
    }
}
