<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * date_create()/DateTime::__construct() $datetime — typed string default "now" (ext/date/php_date.c; #18730).
 *
 * php-src: null/empty datetime coerces to "" which constructs current time.
 */
final class VmDateTimeCreateArg
{
    /**
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceDatetime(
        Frame $frame,
        Variable $var,
        string $function,
        int $userArgIndex = 0,
        string $paramName = 'datetime'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must be of type string, null given',
                    $function,
                    $userArgIndex + 1,
                    $paramName
                ));
            }
            if (!VmString::requiresForwardProfileStrictStringNull()) {
                VmNullStringParamDeprecation::emit($frame, $function, $userArgIndex, $paramName);
            }

            return '';
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            $resolved = $var->resolveIndirect();
            if (Variable::TYPE_STRING !== $resolved->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must be of type string, %s given',
                    $function,
                    $userArgIndex + 1,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($resolved)
                ));
            }
        }

        return VmString::coerceStringBuiltinArg(
            $var,
            $function,
            $userArgIndex,
            $paramName,
            'string',
            false
        );
    }

    /** Compile-time null datetime literal — "" (now). */
    public static function jitNullDatetimeLiteral(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex = 0,
        string $paramName = 'datetime'
    ): string {
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullString($context, $arg, $function, $paramName, $userArgIndex + 1);
        }
        if (!JitStringBuiltinArg::requiresForwardProfileStrictStringNull()) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                $function,
                $userArgIndex,
                $paramName
            );
        }

        return '';
    }
}
