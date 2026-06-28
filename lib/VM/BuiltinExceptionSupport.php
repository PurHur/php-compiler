<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Minimal Throwable / Error / TypeError VM registration for runtime TypeError dispatch (#3445, #3371).
 *
 * php-src: Zend/zend_exceptions.c
 */
final class BuiltinExceptionSupport
{
    public const CLASS_ERROR = 'error';
    public const CLASS_TYPE_ERROR = 'typeerror';
    public const CLASS_ARGUMENT_COUNT_ERROR = 'argumentcounterror';
    public const CLASS_VALUE_ERROR = 'valueerror';
    public const CLASS_DIVISION_BY_ZERO_ERROR = 'divisionbyzeroerror';
    public const CLASS_ARITHMETIC_ERROR = 'arithmeticerror';
    public const CLASS_ASSERTION_ERROR = 'assertionerror';
    public const CLASS_FIBER_ERROR = 'fibererror';
    public const CLASS_FIBER_STACK_OVERFLOW = 'fiberstackoverflow';
    public const CLASS_COMPILE_ERROR = 'compileerror';
    public const CLASS_PARSE_ERROR = 'parseerror';
    public const CLASS_REFLECTION_EXCEPTION = 'reflectionexception';
    public const CLASS_JSON_EXCEPTION = 'jsonexception';
    public const CLASS_EXCEPTION = 'exception';
    public const CLASS_LOGIC_EXCEPTION = 'logicexception';
    public const CLASS_BAD_METHOD_CALL_EXCEPTION = 'badmethodcallexception';
    public const CLASS_DATE_INVALID_TIME_ZONE_EXCEPTION = 'dateinvalidtimezoneexception';
    public const CLASS_DATE_MALFORMED_INTERVAL_EXCEPTION = 'datemalformedintervalexception';
    public const CLASS_DATE_MALFORMED_PERIOD_EXCEPTION = 'datemalformedperiodexception';
    public const CLASS_DATE_ERROR = 'dateerror';
    public const CLASS_DATE_OBJECT_ERROR = 'dateobjecterror';
    public const CLASS_DATE_RANGE_ERROR = 'daterangeerror';
    public const CLASS_THROWABLE = 'throwable';
    public const PROP_MESSAGE = 'message';

    public static function materializeTypeError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_TYPE_ERROR, $message, $file, $line);
    }

    public static function materializeArgumentCountError(Context $ctx, string $message): Variable
    {
        return self::materializeThrowable($ctx, self::CLASS_ARGUMENT_COUNT_ERROR, $message);
    }

    public static function materializeValueError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_VALUE_ERROR, $message, $file, $line);
    }

    public static function materializeAssertionError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_ASSERTION_ERROR, $message, $file, $line);
    }

    public static function materializeError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_ERROR, $message, $file, $line);
    }

    public static function materializeFiberError(Context $ctx, string $message): Variable
    {
        return self::materializeThrowable($ctx, self::CLASS_FIBER_ERROR, $message);
    }

    public static function materializeFiberStackOverflow(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_FIBER_STACK_OVERFLOW, $message, $file, $line);
    }

    public static function materializeCompileError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_COMPILE_ERROR, $message, $file, $line);
    }

    public static function materializeParseError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_PARSE_ERROR, $message, $file, $line);
    }

    public static function materializeReflectionException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_REFLECTION_EXCEPTION, $message, $file, $line);
    }

    public static function materializeJsonException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        int $code = 0
    ): Variable {
        $var = self::materializeThrowable($ctx, self::CLASS_JSON_EXCEPTION, $message, $file, $line);
        $var->toObject()->getProperty(ExceptionSupport::PROP_CODE)->int($code);

        return $var;
    }

    public static function materializeDateInvalidTimeZoneException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_INVALID_TIME_ZONE_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_DATE_INVALID_TIME_ZONE_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /** php-src ext/date/php_date.c — malformed DateInterval spec (#7129). */
    public static function materializeDateMalformedIntervalException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_MALFORMED_INTERVAL_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_DATE_MALFORMED_INTERVAL_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /** php-src ext/date/php_date.c — malformed DatePeriod spec (#7129). */
    public static function materializeDateMalformedPeriodException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_MALFORMED_PERIOD_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_DATE_MALFORMED_PERIOD_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    public static function materializeDateRangeError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_RANGE_ERROR])) {
            return self::materializeError($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_DATE_RANGE_ERROR, $message, $file, $line);
    }

    public static function materializeDateObjectError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_OBJECT_ERROR])) {
            return self::materializeError($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_DATE_OBJECT_ERROR, $message, $file, $line);
    }

    /** php-src ext/date/php_date.c — malformed DateTime string (#7113; DateMalformedStringException in #6048). */
    public static function materializeException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_EXCEPTION, $message, $file, $line);
    }

    public static function materializeLogicException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_LOGIC_EXCEPTION, $message, $file, $line);
    }

    public static function materializeBadMethodCallException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_BAD_METHOD_CALL_EXCEPTION])) {
            return self::materializeLogicException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_BAD_METHOD_CALL_EXCEPTION, $message, $file, $line);
    }

    public static function materializeNativeError(Context $ctx, \Error $error, string $file = '', int $line = 0): Variable
    {
        if ($error instanceof \CompileError) {
            return self::materializeCompileError($ctx, $error->getMessage(), $file, $line);
        }
        if ($error instanceof \TypeError) {
            return self::materializeTypeError($ctx, $error->getMessage(), $file, $line);
        }
        if ($error instanceof \DivisionByZeroError) {
            return self::materializeDivisionByZeroError($ctx, $error->getMessage());
        }
        if ($error instanceof \ArithmeticError) {
            return self::materializeArithmeticError($ctx, $error->getMessage());
        }
        if ($error instanceof \AssertionError) {
            return self::materializeAssertionError($ctx, $error->getMessage(), $file, $line);
        }
        if ($error instanceof \ValueError) {
            return self::materializeValueError($ctx, $error->getMessage(), $file, $line);
        }

        return self::materializeError($ctx, $error->getMessage(), $file, $line);
    }

    private static function materializeThrowable(
        Context $ctx,
        string $classLc,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[$classLc])) {
            throw new \LogicException("{$classLc} builtin class is not registered");
        }
        $entry = $ctx->classes[$classLc];
        $obj = new ObjectEntry($entry);
        $obj->getProperty(self::PROP_MESSAGE)->string($message);
        ExceptionSupport::stampThrowableSite($obj, $file, $line);
        $obj->constructed = true;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }

    public static function materializeDivisionByZeroError(Context $ctx, string $message): Variable
    {
        if (!isset($ctx->classes[self::CLASS_DIVISION_BY_ZERO_ERROR])) {
            throw new \LogicException('DivisionByZeroError builtin class is not registered');
        }
        $entry = $ctx->classes[self::CLASS_DIVISION_BY_ZERO_ERROR];
        $obj = new ObjectEntry($entry);
        $obj->getProperty(self::PROP_MESSAGE)->string($message);
        $obj->constructed = true;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }

    public static function materializeArithmeticError(Context $ctx, string $message): Variable
    {
        return self::materializeThrowable($ctx, self::CLASS_ARITHMETIC_ERROR, $message);
    }
}
