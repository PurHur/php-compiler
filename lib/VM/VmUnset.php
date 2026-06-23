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
     * Scalar JIT containers always raise on unset offset (Zend zend_unset_dim).
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
