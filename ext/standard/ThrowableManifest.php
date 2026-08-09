<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * Single source of truth for VM/JIT Throwable class hierarchy (#6736).
 *
 * php-src: Zend/zend_exceptions.stub.php, Zend/zend_exceptions.c
 */
final class ThrowableManifest
{
    public const LC_THROWABLE = 'throwable';

    public const LC_EXCEPTION = 'exception';

    public const LC_LOGIC_EXCEPTION = 'logicexception';

    public const LC_BAD_FUNCTION_CALL_EXCEPTION = 'badfunctioncallexception';

    public const LC_BAD_METHOD_CALL_EXCEPTION = 'badmethodcallexception';

    public const LC_DOMAIN_EXCEPTION = 'domainexception';

    public const LC_INVALID_ARGUMENT_EXCEPTION = 'invalidargumentexception';

    public const LC_LENGTH_EXCEPTION = 'lengthexception';

    public const LC_OUT_OF_RANGE_EXCEPTION = 'outofrangeexception';

    public const LC_RUNTIME_EXCEPTION = 'runtimeexception';

    public const LC_OUT_OF_BOUNDS_EXCEPTION = 'outofboundsexception';

    public const LC_OVERFLOW_EXCEPTION = 'overflowexception';

    public const LC_RANGE_EXCEPTION = 'rangeexception';

    public const LC_UNDERFLOW_EXCEPTION = 'underflowexception';

    public const LC_UNEXPECTED_VALUE_EXCEPTION = 'unexpectedvalueexception';

    public const LC_ERROR_EXCEPTION = 'errorexception';

    public const LC_REFLECTION_EXCEPTION = 'reflectionexception';

    public const LC_CLOSED_GENERATOR_EXCEPTION = 'closedgeneratorexception';

    public const LC_REQUEST_PARSE_BODY_EXCEPTION = 'requestparsebodyexception';

    public const LC_DATE_EXCEPTION = 'dateexception';

    public const LC_DATE_INVALID_TIME_ZONE_EXCEPTION = 'dateinvalidtimezoneexception';

    /** php-src ext/date/php_date.stub.php — DateMalformedIntervalStringException (#20779). */
    public const LC_DATE_MALFORMED_INTERVAL_STRING_EXCEPTION = 'datemalformedintervalstringexception';

    public const LC_DATE_MALFORMED_STRING = 'datemalformedstring';

    public const LC_DATE_ERROR = 'dateerror';

    public const LC_DATE_OBJECT_ERROR = 'dateobjecterror';

    public const LC_DATE_RANGE_ERROR = 'daterangeerror';

    public const LC_ERROR = 'error';

    public const LC_TYPE_ERROR = 'typeerror';

    public const LC_VALUE_ERROR = 'valueerror';

    public const LC_FIBER_ERROR = 'fibererror';

    public const LC_FIBER_STACK_OVERFLOW = 'fiberstackoverflow';

    public const LC_ARGUMENT_COUNT_ERROR = 'argumentcounterror';

    public const LC_PARSE_ERROR = 'parseerror';

    public const LC_COMPILE_ERROR = 'compileerror';

    public const LC_UNHANDLED_MATCH_ERROR = 'unhandledmatcherror';

    public const LC_ARITHMETIC_ERROR = 'arithmeticerror';

    public const LC_DIVISION_BY_ZERO_ERROR = 'divisionbyzeroerror';

    public const LC_ASSERTION_ERROR = 'assertionerror';

    public const LC_JSON_EXCEPTION = 'jsonexception';

    public const LC_DOM_EXCEPTION = 'domexception';

    public const LC_SODIUM_EXCEPTION = 'sodiumexception';

    public const LC_INTL_EXCEPTION = 'intlexception';

    /**
     * Parent map in registration order: child => parent name, or null when implementing Throwable directly.
     *
     * @var array<string, string|null>
     */
    public const PARENTS = [
        'Exception' => null,
        'LogicException' => 'Exception',
        'BadFunctionCallException' => 'LogicException',
        'BadMethodCallException' => 'BadFunctionCallException',
        'DomainException' => 'LogicException',
        'InvalidArgumentException' => 'LogicException',
        'LengthException' => 'LogicException',
        'OutOfRangeException' => 'LogicException',
        'RuntimeException' => 'Exception',
        'OutOfBoundsException' => 'RuntimeException',
        'OverflowException' => 'RuntimeException',
        'RangeException' => 'RuntimeException',
        'UnderflowException' => 'RuntimeException',
        'UnexpectedValueException' => 'RuntimeException',
        'ErrorException' => 'Exception',
        'ReflectionException' => 'Exception',
        'ClosedGeneratorException' => 'Exception',
        'RequestParseBodyException' => 'Exception',
        'StreamException' => 'Exception',
        'DateException' => 'Exception',
        'DateInvalidTimeZoneException' => 'DateException',
        'DateInvalidOperationException' => 'DateException',
        'DateMalformedStringException' => 'DateException',
        'DateMalformedIntervalStringException' => 'DateException',
        'DateMalformedPeriodStringException' => 'DateException',
        'Error' => null,
        'DateError' => 'Error',
        'DateObjectError' => 'DateError',
        'DateRangeError' => 'DateError',
        'TypeError' => 'Error',
        'ValueError' => 'Error',
        'FiberError' => 'Error',
        'FiberStackOverflow' => 'Error',
        'ArgumentCountError' => 'TypeError',
        // php-src Zend/zend_exceptions.stub.php — ParseError extends CompileError (#25420).
        'CompileError' => 'Error',
        'ParseError' => 'CompileError',
        'UnhandledMatchError' => 'Error',
        'ArithmeticError' => 'Error',
        'DivisionByZeroError' => 'ArithmeticError',
        'AssertionError' => 'Error',
        'JsonException' => 'Exception',
        'DOMException' => 'Exception',
        'PharException' => 'Exception',
        'SodiumException' => 'Exception',
        'IntlException' => 'Exception',
        'SQLite3Exception' => 'Exception',
        'PDOException' => 'RuntimeException',
        'RedisException' => 'Exception',
        'RedisClusterException' => 'RuntimeException',
        'RarException' => 'Exception',
        'SimdJsonException' => 'RuntimeException',
        'SimdJsonValueError' => 'ValueError',
        'SoapFault' => 'Exception',
        'mysqli_sql_exception' => 'RuntimeException',
    ];

    /** @var array<string, class-string> */
    public const NATIVE_CLASSES = [
        'Exception' => \Exception::class,
        'LogicException' => \LogicException::class,
        'BadFunctionCallException' => \BadFunctionCallException::class,
        'BadMethodCallException' => \BadMethodCallException::class,
        'DomainException' => \DomainException::class,
        'InvalidArgumentException' => \InvalidArgumentException::class,
        'LengthException' => \LengthException::class,
        'OutOfRangeException' => \OutOfRangeException::class,
        'RuntimeException' => \RuntimeException::class,
        'OutOfBoundsException' => \OutOfBoundsException::class,
        'OverflowException' => \OverflowException::class,
        'RangeException' => \RangeException::class,
        'UnderflowException' => \UnderflowException::class,
        'UnexpectedValueException' => \UnexpectedValueException::class,
        'ErrorException' => \ErrorException::class,
        'ReflectionException' => \ReflectionException::class,
        'ClosedGeneratorException' => \ClosedGeneratorException::class,
        // Literal FQCN — DateException absent on PHP 8.2 compile hosts (#16828).
        'DateException' => 'DateException',
        'Error' => \Error::class,
        'TypeError' => \TypeError::class,
        'ValueError' => \ValueError::class,
        'ArgumentCountError' => \ArgumentCountError::class,
        'ParseError' => \ParseError::class,
        'CompileError' => \CompileError::class,
        'UnhandledMatchError' => \UnhandledMatchError::class,
        'ArithmeticError' => \ArithmeticError::class,
        'DivisionByZeroError' => \DivisionByZeroError::class,
        'AssertionError' => \AssertionError::class,
        'JsonException' => \JsonException::class,
        'DOMException' => \DOMException::class,
        'PharException' => 'PharException',
        'SodiumException' => \SodiumException::class,
        'IntlException' => \IntlException::class,
        'SQLite3Exception' => 'SQLite3Exception',
        'PDOException' => 'PDOException',
        'RedisException' => 'RedisException',
        'RedisClusterException' => 'RedisClusterException',
        'RarException' => 'RarException',
        'SimdJsonException' => \PHPCompiler\ext\simdjson\SimdJsonException::class,
        'SimdJsonValueError' => \PHPCompiler\ext\simdjson\SimdJsonValueError::class,
        'SoapFault' => 'SoapFault',
        'mysqli_sql_exception' => 'mysqli_sql_exception',
    ];

    /** @return list<string> */
    public static function registrationOrder(): array
    {
        return array_keys(self::PARENTS);
    }

    /** Whether a Throwable class should be registered for the active version profile (#13118, #13124). */
    public static function isAdvertised(string $className): bool
    {
        return match ($className) {
            'DateException',
            'DateInvalidTimeZoneException',
            'DateInvalidOperationException',
            'DateMalformedStringException',
            'DateMalformedIntervalStringException',
            'DateMalformedPeriodStringException',
            'DateError',
            'DateObjectError',
            'DateRangeError' => CompilerVersion::advertisesDateExceptionHierarchy(),
            // Non-php-src phantom names from #7129/#15382 — never advertise (#20779).
            'DateMalformedIntervalException',
            'DateMalformedPeriodException' => false,
            'RequestParseBodyException' => CompilerVersion::advertisesRequestParseBodyExceptionClass(),
            // PHP 8.4+ only — withhold on 8.2 reference profile (#26741, re-#7267).
            'FiberStackOverflow' => CompilerVersion::advertisesFiberStackOverflowClass(),
            'StreamException' => CompilerVersion::advertisesStreamErrorApi(),
            'PharException' => \PHPCompiler\ext\phar\PharExtensionPolicy::advertisesExtension(),
            'IntlException' => \PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesExtension(),
            'SQLite3Exception' => \PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExceptionClass(),
            'PDOException' => \PHPCompiler\ext\pdo\PdoExtensionPolicy::advertisesExceptionClass(),
            'RedisException' => \PHPCompiler\ext\redis\RedisExtensionPolicy::advertisesExtension(),
            'RedisClusterException' => \PHPCompiler\ext\redis\RedisExtensionPolicy::advertisesExtension(),
            'RarException' => \PHPCompiler\ext\rar\RarExtensionPolicy::advertisesExtension(),
            'SimdJsonException' => \PHPCompiler\ext\simdjson\SimdjsonExtensionPolicy::advertisesExtension(),
            'SimdJsonValueError' => \PHPCompiler\ext\simdjson\SimdjsonExtensionPolicy::advertisesExtension(),
            'SoapFault' => \PHPCompiler\ext\soap\SoapExtensionPolicy::advertisesExceptionClass(),
            'mysqli_sql_exception' => \PHPCompiler\ext\mysqli\MysqliExtensionPolicy::advertisesExtension(),
            default => true,
        };
    }

    public static function lcKey(string $className): string
    {
        return strtolower($className);
    }

    public static function parentName(string $className): ?string
    {
        if (!array_key_exists($className, self::PARENTS)) {
            return null;
        }

        return self::PARENTS[$className];
    }

    public static function parentLc(string $className): ?string
    {
        $parent = self::parentName($className);
        if (null === $parent) {
            return null;
        }

        return self::lcKey($parent);
    }

    public static function isDescendantOf(string $lc, string $ancestorLc): bool
    {
        if ($lc === $ancestorLc) {
            return true;
        }
        $name = self::nameForLc($lc);
        if (null === $name) {
            return false;
        }
        while (true) {
            $parentName = self::parentName($name);
            if (null === $parentName) {
                return false;
            }
            if (self::lcKey($parentName) === $ancestorLc) {
                return true;
            }
            $name = $parentName;
        }
    }

    /** @return class-string|null */
    public static function nativeClass(string $className): ?string
    {
        return self::NATIVE_CLASSES[$className] ?? null;
    }

    /** @return class-string|null */
    public static function nativeClassForLc(string $lc): ?string
    {
        $name = self::nameForLc($lc);
        if (null === $name) {
            return null;
        }

        return self::nativeClass($name);
    }

    public static function nameForLc(string $lc): ?string
    {
        static $map = null;
        if (null === $map) {
            $map = [];
            foreach (array_keys(self::PARENTS) as $name) {
                $map[self::lcKey($name)] = $name;
            }
        }

        return $map[$lc] ?? null;
    }
}
