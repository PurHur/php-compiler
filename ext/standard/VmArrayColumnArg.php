<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * array_column() column_key / index_key guards (php-src ext/standard/array.c, #5974).
 */
final class VmArrayColumnArg
{
    public const TYPE_LABEL = 'string|int|null';

    /**
     * @throws \TypeError
     */
    public static function rejectEnumCaseStrIntNullArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        throw new \TypeError(self::strIntNullTypeError(
            $function,
            $argIndex,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * @throws \TypeError|\LogicException
     *
     * @return string|int
     */
    public static function requireStrIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): string|int {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseStrIntNullArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        throw new \TypeError(self::strIntNullTypeError(
            $function,
            $argIndex,
            $paramName,
            self::vmTypeName($var->type)
        ));
    }

    public static function vmTypeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            case Variable::TYPE_ENUM_CASE:
                return 'object';
            default:
                return 'mixed';
        }
    }

    public static function strIntNullTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            self::TYPE_LABEL,
            $given
        );
    }
}
