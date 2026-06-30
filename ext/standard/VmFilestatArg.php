<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/** Shared VM argument guards for filestat permission builtins (php-src ext/standard/filestat.c; #6079). */
final class VmFilestatArg
{
    /**
     * Z_PARAM_PATH filename — null coerces to "" (php-src filestat.c; #13354, same as touch()).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceFilenameArg(Variable $var, string $function): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, 0, 'filename');
    }

    /**
     * Z_PARAM_PATH with caller strict_types parity (#13419, ext/standard/filestat.c).
     *
     * @throws \TypeError when caller strict_types rejects null operands
     */
    public static function filenameArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        string $paramName = 'filename'
    ): string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, $argIndex, $function, $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toString();
        }

        return self::coerceFilenameArg($frame->calledArgs[$argIndex], $function);
    }

    /**
     * Z_PARAM_PATH for touch() — null coerces to "" then php_touch returns false (#12878, php_touch).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coercePathArg(Variable $var, string $function): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, 0, 'filename');
    }

    /**
     * @throws \TypeError
     */
    public static function rejectEnumCaseIntOrStringArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        throw new \TypeError(self::intOrStringTypeError(
            $function,
            $argIndex,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * @throws \TypeError
     */
    public static function rejectEnumCaseIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        throw new \TypeError(self::intTypeError(
            $function,
            $argIndex,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * @throws \TypeError|\LogicException
     */
    public static function requireIntOrStringArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): Variable {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseIntOrStringArg($var, $function, $argIndex, $paramName);
        if (!\in_array($var->type, [Variable::TYPE_INTEGER, Variable::TYPE_STRING], true)) {
            throw new \LogicException(
                $function.'() '.$paramName.' must be int or string in this compiler build'
            );
        }

        return $var;
    }

    /**
     * @throws \TypeError|\LogicException
     */
    public static function requireIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseIntArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(self::intTypeError(
                $function,
                $argIndex,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return $var->toInt();
    }

    private static function intOrStringTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type string|int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }

    private static function intTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }
}
