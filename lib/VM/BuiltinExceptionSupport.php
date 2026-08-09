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
    public const CLASS_DOM_EXCEPTION = 'domexception';
    public const CLASS_SODIUM_EXCEPTION = 'sodiumexception';
    public const CLASS_INTL_EXCEPTION = 'intlexception';
    public const CLASS_REDIS_EXCEPTION = 'redisexception';
    public const CLASS_REDIS_CLUSTER_EXCEPTION = 'redisclusterexception';
    public const CLASS_RAR_EXCEPTION = 'rarexception';
    public const CLASS_SIMDJSON_EXCEPTION = 'simdjsonexception';

    public const CLASS_SIMDJSON_VALUE_ERROR = 'simdjsonvalueerror';
    public const CLASS_PDO_EXCEPTION = 'pdoexception';
    public const CLASS_SQLITE3_EXCEPTION = 'sqlite3exception';
    public const CLASS_PHAR_EXCEPTION = 'pharexception';
    public const CLASS_SOAP_FAULT = 'soapfault';
    public const CLASS_FFI_EXCEPTION = 'ffi\\exception';
    public const CLASS_FFI_PARSER_EXCEPTION = 'ffi\\parserexception';
    public const CLASS_EXCEPTION = 'exception';
    public const CLASS_LOGIC_EXCEPTION = 'logicexception';
    public const CLASS_INVALID_ARGUMENT_EXCEPTION = 'invalidargumentexception';
    public const CLASS_BAD_METHOD_CALL_EXCEPTION = 'badmethodcallexception';
    public const CLASS_OUT_OF_BOUNDS_EXCEPTION = 'outofboundsexception';
    /** PHP 8.4+ request_parse_body() (#5965, ext/standard/http.c). */
    public const CLASS_REQUEST_PARSE_BODY_EXCEPTION = 'requestparsebodyexception';
    /** php-src ext/uri — Uri\InvalidUriException (#21468). */
    public const CLASS_INVALID_URI_EXCEPTION = 'uri\\invaliduriexception';
    /** php-src ext/uri — Uri\WhatWg\InvalidUrlException (#21468). */
    public const CLASS_INVALID_URL_EXCEPTION = 'uri\\whatwg\\invalidurlexception';
    /** php-src ext/filter — Filter\FilterFailedException (#28131). */
    public const CLASS_FILTER_FAILED_EXCEPTION = 'filter\\filterfailedexception';
    public const CLASS_DATE_INVALID_TIME_ZONE_EXCEPTION = 'dateinvalidtimezoneexception';
    /** php-src DateMalformedIntervalStringException (#20779). */
    public const CLASS_DATE_MALFORMED_INTERVAL_STRING_EXCEPTION = 'datemalformedintervalstringexception';
    public const CLASS_DATE_MALFORMED_STRING_EXCEPTION = 'datemalformedstringexception';
    public const CLASS_DATE_INVALID_OPERATION_EXCEPTION = 'dateinvalidoperationexception';
    /** php-src ext/mysqli — mysqli_sql_exception (#21803). */
    public const CLASS_MYSQLI_SQL_EXCEPTION = 'mysqli_sql_exception';
    public const CLASS_DATE_MALFORMED_PERIOD_STRING_EXCEPTION = 'datemalformedperiodstringexception';
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

    public static function materializeArgumentCountError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_ARGUMENT_COUNT_ERROR, $message, $file, $line);
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

    public static function materializeFiberError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_FIBER_ERROR, $message, $file, $line);
    }

    public static function materializeFiberStackOverflow(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        // Class withheld on ≤8.2 reference profile (#26741) — fall back to Error like Zend 8.2.
        if (!isset($ctx->classes[self::CLASS_FIBER_STACK_OVERFLOW])) {
            return self::materializeError($ctx, $message, $file, $line);
        }

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

    public static function materializeDomException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        int $code = 0
    ): Variable {
        $var = self::materializeThrowable($ctx, self::CLASS_DOM_EXCEPTION, $message, $file, $line);
        $var->toObject()->getProperty(ExceptionSupport::PROP_CODE)->int($code);

        return $var;
    }

    public static function materializeSodiumException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_SODIUM_EXCEPTION, $message, $file, $line);
    }

    /** Bridge native IntlException from ext/intl builtins (#22577). */
    public static function materializeIntlException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_INTL_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_INTL_EXCEPTION, $message, $file, $line);
    }

    /**
     * Bridge native PDOException — copies public $errorInfo (php-src pdo.stub.php; #22455).
     *
     * @param list<mixed>|null $errorInfo SQLSTATE / driver code / message triple
     */
    public static function materializePDOException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        int $code = 0,
        ?array $errorInfo = null
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_PDO_EXCEPTION])) {
            return self::materializeRuntimeException($ctx, $message, $file, $line);
        }
        $var = self::materializeThrowable($ctx, self::CLASS_PDO_EXCEPTION, $message, $file, $line);
        $obj = $var->toObject();
        $obj->getProperty(ExceptionSupport::PROP_CODE)->int($code);
        if (null !== $errorInfo && $obj->hasProperty('errorInfo')) {
            $ht = new HashTable();
            foreach (\array_values($errorInfo) as $i => $cell) {
                $slot = new Variable();
                if (null === $cell) {
                    $slot->null();
                } elseif (\is_int($cell)) {
                    $slot->int($cell);
                } elseif (\is_float($cell)) {
                    $slot->float($cell);
                } elseif (\is_bool($cell)) {
                    $slot->bool($cell);
                } else {
                    $slot->string((string) $cell);
                }
                $ht->add((string) $i, $slot);
            }
            $obj->getProperty('errorInfo')->array($ht);
        }

        return $var;
    }

    /** Bridge native SoapFault from ext/soap builtins (#20124, #20219). */
    public static function materializeSoapFault(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        string $faultcode = '',
        string $faultstring = '',
        string $faultactor = '',
        mixed $detail = null,
        string $name = ''
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_SOAP_FAULT])) {
            return self::materializeException($ctx, $message, $file, $line);
        }
        $var = self::materializeThrowable($ctx, self::CLASS_SOAP_FAULT, $message, $file, $line);
        $obj = $var->toObject();
        if ('' !== $faultcode) {
            $obj->getProperty('faultcode')->string($faultcode);
        }
        $fs = '' !== $faultstring ? $faultstring : $message;
        $obj->getProperty('faultstring')->string($fs);
        if ('' !== $faultactor) {
            $obj->getProperty('faultactor')->string($faultactor);
        }
        if (null !== $detail) {
            if (\is_string($detail)) {
                $obj->getProperty('detail')->string($detail);
            } elseif (\is_int($detail)) {
                $obj->getProperty('detail')->int($detail);
            } elseif (\is_bool($detail)) {
                $obj->getProperty('detail')->bool($detail);
            } elseif (\is_float($detail)) {
                $obj->getProperty('detail')->float($detail);
            } else {
                $obj->getProperty('detail')->string((string) $detail);
            }
        }
        if ('' !== $name) {
            $obj->getProperty('_name')->string($name);
        }

        return $var;
    }

    /** Bridge native FFI\Exception / FFI\ParserException from ext/ffi builtins (#4420). */
    public static function materializeFfiException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        bool $parser = false
    ): Variable {
        $classLc = $parser ? self::CLASS_FFI_PARSER_EXCEPTION : self::CLASS_FFI_EXCEPTION;
        if (!isset($ctx->classes[$classLc])) {
            return self::materializeError($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, $classLc, $message, $file, $line);
    }

    public static function materializeSQLite3Exception(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        int $code = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_SQLITE3_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }
        $var = self::materializeThrowable($ctx, self::CLASS_SQLITE3_EXCEPTION, $message, $file, $line);
        $var->toObject()->getProperty(ExceptionSupport::PROP_CODE)->int($code);

        return $var;
    }

    public static function materializePharException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_PHAR_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_PHAR_EXCEPTION, $message, $file, $line);
    }

    public static function materializeRedisException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_REDIS_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_REDIS_EXCEPTION, $message, $file, $line);
    }

    /** Materialize RedisClusterException (extends RuntimeException; #28094). */
    public static function materializeRedisClusterException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_REDIS_CLUSTER_EXCEPTION])) {
            return self::materializeRuntimeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_REDIS_CLUSTER_EXCEPTION, $message, $file, $line);
    }

    public static function materializeRarException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_RAR_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_RAR_EXCEPTION, $message, $file, $line);
    }

    public static function materializeSimdJsonException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        int $code = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_SIMDJSON_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }
        $var = self::materializeThrowable($ctx, self::CLASS_SIMDJSON_EXCEPTION, $message, $file, $line);
        $var->toObject()->getProperty(ExceptionSupport::PROP_CODE)->int($code);

        return $var;
    }

    public static function materializeSimdJsonValueError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_SIMDJSON_VALUE_ERROR])) {
            return self::materializeValueError($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_SIMDJSON_VALUE_ERROR, $message, $file, $line);
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

    /** php-src ext/date/php_date.c — malformed DateTime string (#7113, #16926). */
    public static function materializeDateMalformedStringException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_MALFORMED_STRING_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_DATE_MALFORMED_STRING_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /** php-src ext/date/php_date.c — illegal date mutation (#6048). */
    public static function materializeDateInvalidOperationException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_INVALID_OPERATION_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_DATE_INVALID_OPERATION_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /** php-src ext/date/php_date.c — malformed DateInterval spec (#20779, was misnamed in #7129). */
    public static function materializeDateMalformedIntervalException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeDateMalformedIntervalStringException($ctx, $message, $file, $line);
    }

    /** php-src ext/date/php_date.stub.php — DateMalformedIntervalStringException (#20779). */
    public static function materializeDateMalformedIntervalStringException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_MALFORMED_INTERVAL_STRING_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_DATE_MALFORMED_INTERVAL_STRING_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /** php-src ext/date/php_date.c — malformed DatePeriod ISO8601 spec (#7296). */
    public static function materializeDateMalformedPeriodStringException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_DATE_MALFORMED_PERIOD_STRING_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_DATE_MALFORMED_PERIOD_STRING_EXCEPTION,
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

    /**
     * Bridge host RequestParseBodyException into the VM builtin class (#5965).
     *
     * Without this, executeInternalHandler flattens all \Exception subclasses to Exception,
     * so `catch (RequestParseBodyException)` never matches.
     */
    public static function materializeRequestParseBodyException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_REQUEST_PARSE_BODY_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_REQUEST_PARSE_BODY_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /**
     * Bridge host Filter\FilterFailedException into the VM builtin class (#28131).
     */
    public static function materializeFilterFailedException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_FILTER_FAILED_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_FILTER_FAILED_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /**
     * Bridge host Uri\InvalidUriException into the VM builtin class (#21468).
     *
     * Without this, executeInternalHandler flattens all \Exception subclasses to Exception.
     */
    public static function materializeInvalidUriException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_INVALID_URI_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_INVALID_URI_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    /**
     * Bridge host Uri\WhatWg\InvalidUrlException into the VM builtin class (#21468).
     */
    public static function materializeInvalidUrlException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_INVALID_URL_EXCEPTION])) {
            return self::materializeInvalidUriException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            self::CLASS_INVALID_URL_EXCEPTION,
            $message,
            $file,
            $line
        );
    }

    public static function materializeInvalidArgumentException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_INVALID_ARGUMENT_EXCEPTION])) {
            return self::materializeLogicException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_INVALID_ARGUMENT_EXCEPTION, $message, $file, $line);
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

    public static function materializeOutOfBoundsException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_OUT_OF_BOUNDS_EXCEPTION])) {
            return self::materializeRuntimeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, self::CLASS_OUT_OF_BOUNDS_EXCEPTION, $message, $file, $line);
    }

    public static function materializeRuntimeException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes['runtimeexception'])) {
            return self::materializeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable($ctx, 'runtimeexception', $message, $file, $line);
    }

    public static function materializeUnexpectedValueException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        if (!isset($ctx->classes[ExceptionSupport::CLASS_UNEXPECTED_VALUE_EXCEPTION])) {
            return self::materializeRuntimeException($ctx, $message, $file, $line);
        }

        return self::materializeThrowable(
            $ctx,
            ExceptionSupport::CLASS_UNEXPECTED_VALUE_EXCEPTION,
            $message,
            $file,
            $line
        );
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
            return self::materializeDivisionByZeroError($ctx, $error->getMessage(), $file, $line);
        }
        if ($error instanceof \ArithmeticError) {
            return self::materializeArithmeticError($ctx, $error->getMessage(), $file, $line);
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
        // php-src zend_throw_exception / zend_exception_get_props: default code is 0 (#22945).
        $obj->getProperty(ExceptionSupport::PROP_CODE)->int(0);
        ExceptionSupport::stampThrowableSite($obj, $file, $line);
        $obj->constructed = true;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }

    public static function materializeDivisionByZeroError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_DIVISION_BY_ZERO_ERROR, $message, $file, $line);
    }

    public static function materializeArithmeticError(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0
    ): Variable {
        return self::materializeThrowable($ctx, self::CLASS_ARITHMETIC_ERROR, $message, $file, $line);
    }

    /**
     * Bridge native mysqli_sql_exception — copies protected $sqlstate (#22456).
     * php-src: ext/mysqli/mysqli_exception.c — default SQLSTATE "00000".
     */
    public static function materializeMysqliSqlException(
        Context $ctx,
        string $message,
        string $file = '',
        int $line = 0,
        int $code = 0,
        string $sqlstate = '00000'
    ): Variable {
        if (!isset($ctx->classes[self::CLASS_MYSQLI_SQL_EXCEPTION])) {
            return self::materializeException($ctx, $message, $file, $line);
        }
        $var = self::materializeThrowable($ctx, self::CLASS_MYSQLI_SQL_EXCEPTION, $message, $file, $line);
        $obj = $var->toObject();
        $obj->getProperty(ExceptionSupport::PROP_CODE)->int($code);
        if ($obj->hasProperty('sqlstate')) {
            $obj->getProperty('sqlstate')->string('' !== $sqlstate ? $sqlstate : '00000');
        }

        return $var;
    }
}
