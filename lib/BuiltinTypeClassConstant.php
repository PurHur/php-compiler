<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Builtin type {@code ::class} pseudo-constants (PHP 8.0+, Zend/zend_compile.c).
 */
final class BuiltinTypeClassConstant
{
    /** @var array<string, string> lowercase operand => canonical ::class string */
    private const TYPE_CLASS_NAMES = [
        'int' => 'int',
        'integer' => 'int',
        'float' => 'float',
        'double' => 'float',
        'bool' => 'bool',
        'boolean' => 'bool',
        'string' => 'string',
        'array' => 'array',
        'object' => 'object',
        'callable' => 'callable',
        'iterable' => 'iterable',
        'void' => 'void',
        'never' => 'never',
        'mixed' => 'mixed',
        'null' => 'null',
        'false' => 'false',
        'true' => 'true',
    ];

    public static function classNameForTypeOperand(string $typeName): ?string
    {
        return self::TYPE_CLASS_NAMES[strtolower(ltrim($typeName, '\\'))] ?? null;
    }
}
