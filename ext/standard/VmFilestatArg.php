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
     * Z_PARAM_PATH filename — null coerces to "" (deprecated) + reject embedded NUL (php-src filestat.c; #14597).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceFilenameArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'filename',
        ?Frame $frame = null
    ): string {
        if (null !== $frame) {
            InternalStrictArg::rejectNullString($var, $function, $paramName, $argIndex, $frame);
        }

        return VmString::coercePathBuiltinArg($var, $function, $argIndex, $paramName);
    }

    /**
     * Z_PARAM_PATH for compiled call sites — null coerces to "" (deprecated).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function filenameArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        string $paramName = 'filename'
    ): string {
        return self::coerceFilenameArg(
            $frame->calledArgs[$argIndex],
            $function,
            $argIndex,
            $paramName,
            $frame
        );
    }

    /** True when the path operand is null before Z_PARAM_PATH coercion (php-src filestat.c; #14641). */
    public static function wasNullFilenameArg(Variable $var): bool
    {
        return Variable::TYPE_NULL === $var->resolveIndirect()->type;
    }

    /**
     * Emit stat/lstat failure warning unless null was coerced to "" (#14641, php-src deprecation path).
     */
    public static function warnPathStatFailedForFilenameArg(
        Frame $frame,
        Variable $filenameArg,
        string $function,
        string $path,
        bool $lstat
    ): void {
        if (!self::wasNullFilenameArg($filenameArg)) {
            VmFilestatFailure::warnPathStatFailed($frame, $function, $path, $lstat);
        }
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
     * Z_PARAM_STR_OR_LONG user/group — null coerces to int 0 when caller is non-strict (#14673).
     *
     * @throws \TypeError|\LogicException
     */
    public static function requireIntOrStringArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        string $paramName
    ): Variable {
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        self::rejectEnumCaseIntOrStringArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_NULL === $var->type) {
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(self::intOrStringTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    'null'
                ));
            }
            $coerced = new Variable();
            $coerced->int(0);

            return $coerced;
        }
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

    /**
     * chmod()/mkdir() mode — Z_PARAM_LONG with caller strict_types (#4207, ext/standard/filestat.c).
     *
     * @throws \TypeError
     */
    public static function parseFileModeArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        string $paramName
    ): int {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return self::requireIntArg($frame->calledArgs[$argIndex], $function, $argIndex, $paramName);
        }

        return self::parseFileModeArg($frame->calledArgs[$argIndex], $function, $argIndex, $paramName);
    }

    /**
     * Weak-mode file mode coercion — Z_PARAM_LONG decimal cast for numeric strings (#15902, ext/standard/filestat.c).
     *
     * @throws \TypeError
     */
    public static function parseFileModeArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        return VmMath::parseIntBuiltinArg($var, $function, $argIndex + 1, $paramName);
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
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
