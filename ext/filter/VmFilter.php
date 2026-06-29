<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmInetPure;
use PHPCompiler\ext\standard\VmPregNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * filter_var() / filter_input() subset (issue #104, #6028).
 *
 * php-src: ext/filter/filter.c, logical_filters.c
 */
final class VmFilter
{
    public const FILTER_FLAG_NONE = 0;
    public const FILTER_REQUIRE_ARRAY = 0x1000000;
    public const FILTER_REQUIRE_SCALAR = 0x2000000;
    public const FILTER_FORCE_ARRAY = 0x4000000;
    public const FILTER_NULL_ON_FAILURE = 0x8000000;
    public const FILTER_THROW_ON_FAILURE = 0x10000000;
    public const FILTER_FLAG_ALLOW_OCTAL = 0x0001;
    public const FILTER_FLAG_ALLOW_HEX = 0x0002;
    public const FILTER_FLAG_STRIP_LOW = 0x0004;
    public const FILTER_FLAG_STRIP_HIGH = 0x0008;
    public const FILTER_FLAG_ENCODE_LOW = 0x0010;
    public const FILTER_FLAG_ENCODE_HIGH = 0x0020;
    public const FILTER_FLAG_ENCODE_AMP = 0x0040;
    public const FILTER_FLAG_NO_ENCODE_QUOTES = 0x0080;
    public const FILTER_FLAG_EMPTY_STRING_NULL = 0x0100;
    public const FILTER_FLAG_STRIP_BACKTICK = 0x0200;
    public const FILTER_FLAG_ALLOW_FRACTION = 0x1000;
    public const FILTER_FLAG_ALLOW_THOUSAND = 0x2000;
    public const FILTER_FLAG_ALLOW_SCIENTIFIC = 0x4000;
    public const FILTER_FLAG_PATH_REQUIRED = 0x010000;
    public const FILTER_FLAG_QUERY_REQUIRED = 0x080000;
    public const FILTER_FLAG_IPV4 = 0x00100000;
    public const FILTER_FLAG_IPV6 = 0x00200000;
    public const FILTER_FLAG_NO_RES_RANGE = 0x00400000;
    public const FILTER_FLAG_NO_PRIV_RANGE = 0x00800000;
    public const FILTER_FLAG_GLOBAL_RANGE = 0x20000000;
    public const FILTER_FLAG_HOSTNAME = 0x100000;
    public const FILTER_FLAG_EMAIL_UNICODE = 0x100000;
    public const FILTER_VALIDATE_INT = 0x0101;
    /** php-src ext/filter/php_filter.h — FILTER_VALIDATE_BOOLEAN */
    public const FILTER_VALIDATE_BOOLEAN = 0x0102;
    /** php-src ext/filter/php_filter.h — FILTER_VALIDATE_FLOAT */
    public const FILTER_VALIDATE_FLOAT = 0x0103;
    /** php-src ext/filter/filter_private.h — FILTER_VALIDATE_REGEXP */
    public const FILTER_VALIDATE_REGEXP = 0x0110;
    /** php-src ext/filter/php_filter.h — FILTER_VALIDATE_URL */
    public const FILTER_VALIDATE_URL = 0x0111;
    public const FILTER_VALIDATE_EMAIL = 0x0112;
    /** php-src ext/filter/php_filter.h — FILTER_VALIDATE_IP */
    public const FILTER_VALIDATE_IP = 0x0113;
    public const FILTER_VALIDATE_MAC = 0x0114;
    public const FILTER_VALIDATE_DOMAIN = 0x0115;
    public const FILTER_DEFAULT = 0x0204;
    public const FILTER_UNSAFE_RAW = 0x0204;
    public const FILTER_SANITIZE_STRING = 0x0201;
    public const FILTER_SANITIZE_ENCODED = 0x0202;
    public const FILTER_SANITIZE_SPECIAL_CHARS = 0x0203;
    public const FILTER_SANITIZE_EMAIL = 0x0205;
    public const FILTER_SANITIZE_URL = 0x0206;
    public const FILTER_SANITIZE_NUMBER_INT = 0x0207;
    public const FILTER_SANITIZE_NUMBER_FLOAT = 0x0208;
    public const FILTER_SANITIZE_FULL_SPECIAL_CHARS = 0x020a;
    public const FILTER_SANITIZE_ADD_SLASHES = 0x020b;
    public const FILTER_CALLBACK = 0x0400;
    /** php-src ext/filter/php_filter.h */
    public const INPUT_POST = 0;

    public const INPUT_GET = 1;

    public const INPUT_COOKIE = 2;

    public const INPUT_ENV = 4;

    public const INPUT_SERVER = 5;

    public const INPUT_SESSION = 6;

    public static function isSupportedFilter(int $filter): bool
    {
        return self::isValidateFilter($filter) || self::isSanitizeFilter($filter);
    }

    public static function isValidateFilter(int $filter): bool
    {
        return self::FILTER_VALIDATE_INT === $filter
            || self::FILTER_VALIDATE_BOOLEAN === $filter
            || self::FILTER_VALIDATE_FLOAT === $filter
            || self::FILTER_VALIDATE_REGEXP === $filter
            || self::FILTER_VALIDATE_URL === $filter
            || self::FILTER_VALIDATE_EMAIL === $filter
            || self::FILTER_VALIDATE_IP === $filter;
    }

    public static function isSanitizeFilter(int $filter): bool
    {
        return self::FILTER_SANITIZE_STRING === $filter
            || self::FILTER_SANITIZE_ENCODED === $filter
            || self::FILTER_SANITIZE_SPECIAL_CHARS === $filter
            || self::FILTER_SANITIZE_FULL_SPECIAL_CHARS === $filter
            || self::FILTER_SANITIZE_EMAIL === $filter
            || self::FILTER_SANITIZE_URL === $filter
            || self::FILTER_SANITIZE_NUMBER_INT === $filter
            || self::FILTER_SANITIZE_NUMBER_FLOAT === $filter
            || self::FILTER_SANITIZE_ADD_SLASHES === $filter
            || self::FILTER_UNSAFE_RAW === $filter
            || self::FILTER_DEFAULT === $filter;
    }

    public static function unknownFilterWarningMessage(int $filter): string
    {
        return 'filter_var(): Unknown filter with ID '.$filter;
    }

    public static function filterVar(Variable $value, int $filter, ?Variable $options = null): Variable
    {
        $parsed = self::parseFilterArgs($options);
        $nullOnFailure = 0 !== ($parsed['flags'] & self::FILTER_NULL_ON_FAILURE);
        if (self::FILTER_VALIDATE_INT === $filter) {
            return self::validateInt($value, $nullOnFailure, $parsed['flags'], $parsed['filterOptions']);
        }
        if (self::FILTER_VALIDATE_BOOLEAN === $filter) {
            return self::validateBoolean($value, $nullOnFailure);
        }
        if (self::FILTER_VALIDATE_FLOAT === $filter) {
            return self::validateFloat($value, $nullOnFailure, $parsed['filterOptions']);
        }
        if (self::FILTER_VALIDATE_REGEXP === $filter) {
            return self::validateRegexp($value, $parsed['filterOptions'], $nullOnFailure);
        }
        if (self::FILTER_VALIDATE_URL === $filter) {
            return self::validateUrl($value, $nullOnFailure, $parsed['flags']);
        }
        if (self::FILTER_VALIDATE_EMAIL === $filter) {
            return self::validateEmail($value, $nullOnFailure, $parsed['flags']);
        }
        if (self::FILTER_VALIDATE_IP === $filter) {
            return self::validateIp($value, $nullOnFailure, $parsed['flags']);
        }
        if (self::isSanitizeFilter($filter)) {
            return self::sanitize($value, $filter, $parsed['flags'], $parsed['filterOptions']);
        }

        return self::failureResult(false);
    }

    /**
     * Sanitizing filters (php-src ext/filter/sanitizing_filters.c; #11419).
     */
    public static function sanitize(
        Variable $value,
        int $filter,
        int $flags = 0,
        ?\PHPCompiler\VM\HashTable $filterOptions = null
    ): Variable {
        if (self::FILTER_CALLBACK === $filter) {
            throw new \ValueError('filter_var(): Option must be a valid callback');
        }
        $subject = self::coerceFilterScalarString($value);
        if (null === $subject) {
            return self::failureResult(false);
        }

        $sanitized = match ($filter) {
            self::FILTER_SANITIZE_STRING => self::sanitizeString($subject, $flags),
            self::FILTER_SANITIZE_ENCODED => rawurlencode($subject),
            self::FILTER_SANITIZE_SPECIAL_CHARS => self::sanitizeSpecialChars($subject, $flags),
            self::FILTER_SANITIZE_FULL_SPECIAL_CHARS => VmString::htmlspecialchars(
                $subject,
                StdlibConstants::ENT_QUOTES | StdlibConstants::ENT_SUBSTITUTE,
                'UTF-8',
                true
            ),
            self::FILTER_SANITIZE_EMAIL => self::sanitizeEmail($subject),
            self::FILTER_SANITIZE_URL => self::sanitizeUrl($subject),
            self::FILTER_SANITIZE_NUMBER_INT => self::sanitizeNumberInt($subject),
            self::FILTER_SANITIZE_NUMBER_FLOAT => self::sanitizeNumberFloat($subject),
            self::FILTER_SANITIZE_ADD_SLASHES => VmString::addslashes($subject),
            self::FILTER_UNSAFE_RAW, self::FILTER_DEFAULT => $subject,
            default => null,
        };
        if (null === $sanitized) {
            return self::failureResult(false);
        }

        return self::stringResult($sanitized);
    }

    /**
     * JIT/AOT bridge — returns sanitized string or empty string when input is not scalar.
     */
    public static function sanitizeStringForJit(int $filter, string $subject, int $flags = 0): string
    {
        if (!self::isSanitizeFilter($filter)) {
            return '';
        }

        return match ($filter) {
            self::FILTER_SANITIZE_STRING => self::sanitizeString($subject, $flags),
            self::FILTER_SANITIZE_ENCODED => rawurlencode($subject),
            self::FILTER_SANITIZE_SPECIAL_CHARS => self::sanitizeSpecialChars($subject, $flags),
            self::FILTER_SANITIZE_FULL_SPECIAL_CHARS => VmString::htmlspecialchars(
                $subject,
                StdlibConstants::ENT_QUOTES | StdlibConstants::ENT_SUBSTITUTE,
                'UTF-8',
                true
            ),
            self::FILTER_SANITIZE_EMAIL => self::sanitizeEmail($subject),
            self::FILTER_SANITIZE_URL => self::sanitizeUrl($subject),
            self::FILTER_SANITIZE_NUMBER_INT => self::sanitizeNumberInt($subject),
            self::FILTER_SANITIZE_NUMBER_FLOAT => self::sanitizeNumberFloat($subject),
            self::FILTER_SANITIZE_ADD_SLASHES => VmString::addslashes($subject),
            self::FILTER_UNSAFE_RAW, self::FILTER_DEFAULT => $subject,
            default => '',
        };
    }

    private static function coerceFilterScalarString(Variable $value): ?string
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return '';
        }
        if (Variable::TYPE_STRING === $value->type) {
            return $value->toString();
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            return (string) $value->toInt();
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            return (string) $value->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $value->type) {
            return $value->toBool() ? '1' : '';
        }

        return null;
    }

    private static function stringResult(string $s): Variable
    {
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /** php-src php_filter_sanitize_string — strip_tags + optional flag stripping. */
    private static function sanitizeString(string $subject, int $flags): string
    {
        $out = VmString::stripTags($subject);
        if (0 !== ($flags & self::FILTER_FLAG_STRIP_LOW)) {
            $out = self::stripCharsBelow($out, 32);
        }
        if (0 !== ($flags & self::FILTER_FLAG_STRIP_HIGH)) {
            $out = self::stripCharsAbove($out, 127);
        }
        if (0 !== ($flags & self::FILTER_FLAG_STRIP_BACKTICK)) {
            $out = str_replace('`', '', $out);
        }

        return $out;
    }

    /** php-src php_filter_sanitize_special_chars — numeric entities for <>&"' */
    private static function sanitizeSpecialChars(string $subject, int $flags): string
    {
        $encodeQuotes = 0 === ($flags & self::FILTER_FLAG_NO_ENCODE_QUOTES);
        $out = '';
        $len = strlen($subject);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $subject[$i];
            if ('<' === $ch || '>' === $ch || '&' === $ch || ('"' === $ch && $encodeQuotes) || ("'" === $ch && $encodeQuotes)) {
                $out .= '&#'.ord($ch).';';
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    private static function sanitizeNumberInt(string $subject): string
    {
        return preg_replace('/[^0-9+-]+/', '', $subject) ?? '';
    }

    private static function sanitizeNumberFloat(string $subject): string
    {
        return preg_replace('/[^0-9+-]+/', '', $subject) ?? '';
    }

    /** php-src php_filter_sanitize_email allow-list. */
    private static function sanitizeEmail(string $subject): string
    {
        return preg_replace(
            '/[^0-9a-zA-Z!#$%&\'*+\/=?^_`{|}~@.-]+/',
            '',
            $subject
        ) ?? '';
    }

    /** php-src php_filter_sanitize_url allow-list. */
    private static function sanitizeUrl(string $subject): string
    {
        return preg_replace(
            '/[^-a-zA-Z0-9+&@#\/%?=~_|!:,.;]+/',
            '',
            $subject
        ) ?? '';
    }

    private static function stripCharsBelow(string $s, int $threshold): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            if (ord($s[$i]) >= $threshold) {
                $out .= $s[$i];
            }
        }

        return $out;
    }

    private static function stripCharsAbove(string $s, int $threshold): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            if (ord($s[$i]) <= $threshold) {
                $out .= $s[$i];
            }
        }

        return $out;
    }

    public static function resolveInputType(Variable $var, string $fn): int
    {
        $var = $var->resolveIndirect();
        $fromEnum = self::tryPhpInputFilterInt($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($type) must be of type PhpInputFilter|int, %s given',
                $fn,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #1 ($type) must be of type PhpInputFilter|int, %s given',
            $fn,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    public static function tryPhpInputFilterInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isPhpInputFilterEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('PhpInputFilter case missing backing value');
        }

        return $entry->backingValue->resolveIndirect()->toInt();
    }

    public static function inputSuperglobalName(int $type): string
    {
        return match ($type) {
            self::INPUT_GET => '_GET',
            self::INPUT_POST => '_POST',
            self::INPUT_COOKIE => '_COOKIE',
            self::INPUT_ENV => '_ENV',
            self::INPUT_SERVER => '_SERVER',
            self::INPUT_SESSION => '_SESSION',
            default => throw new \LogicException(
                'filter_input() type '.$type.' is not supported in this compiler build'
            ),
        };
    }

    private static function isPhpInputFilterEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'PhpInputFilter');
    }

    /**
     * @return array{flags: int, filterOptions: ?\PHPCompiler\VM\HashTable}
     */
    private static function parseFilterArgs(?Variable $options): array
    {
        if (null === $options || $options->isUndefined() || Variable::TYPE_NULL === $options->type) {
            return ['flags' => 0, 'filterOptions' => null];
        }
        $resolved = $options->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return ['flags' => $resolved->toInt(), 'filterOptions' => null];
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \LogicException('filter_var() options must be an integer flag bitmask or array');
        }
        $ht = $resolved->toArray();
        $flags = 0;
        $flagsVar = $ht->find('flags');
        if (null !== $flagsVar && !$flagsVar->isUndefined() && Variable::TYPE_NULL !== $flagsVar->type) {
            if (Variable::TYPE_INTEGER !== $flagsVar->resolveIndirect()->type) {
                throw new \LogicException('filter_var() options[flags] must be an integer');
            }
            $flags = $flagsVar->resolveIndirect()->toInt();
        }
        $filterOptions = null;
        $optionsVar = $ht->find('options');
        if (null !== $optionsVar && !$optionsVar->isUndefined() && Variable::TYPE_NULL !== $optionsVar->type) {
            $nested = $optionsVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $nested->type) {
                throw new \LogicException('filter_var() options[options] must be an array');
            }
            $filterOptions = $nested->toArray();
        }

        return ['flags' => $flags, 'filterOptions' => $filterOptions];
    }

    /**
     * @return array{min: int|float|null, max: int|float|null}
     */
    private static function parseRangeOptions(?\PHPCompiler\VM\HashTable $filterOptions, bool $asFloat): array
    {
        $min = null;
        $max = null;
        if (null === $filterOptions) {
            return ['min' => null, 'max' => null];
        }
        $minVar = $filterOptions->find('min_range');
        if (null !== $minVar && !$minVar->isUndefined() && Variable::TYPE_NULL !== $minVar->type) {
            $resolved = $minVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $resolved->type) {
                $min = $asFloat ? (float) $resolved->toInt() : $resolved->toInt();
            } elseif (Variable::TYPE_FLOAT === $resolved->type) {
                $min = $resolved->toFloat();
            }
        }
        $maxVar = $filterOptions->find('max_range');
        if (null !== $maxVar && !$maxVar->isUndefined() && Variable::TYPE_NULL !== $maxVar->type) {
            $resolved = $maxVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $resolved->type) {
                $max = $asFloat ? (float) $resolved->toInt() : $resolved->toInt();
            } elseif (Variable::TYPE_FLOAT === $resolved->type) {
                $max = $resolved->toFloat();
            }
        }

        return ['min' => $min, 'max' => $max];
    }

    private static function intInRange(int $value, int|float|null $min, int|float|null $max): bool
    {
        if (null !== $min && $value < $min) {
            return false;
        }
        if (null !== $max && $value > $max) {
            return false;
        }

        return true;
    }

    private static function floatInRange(float $value, ?float $min, ?float $max): bool
    {
        if (null !== $min && $value < $min) {
            return false;
        }
        if (null !== $max && $value > $max) {
            return false;
        }

        return true;
    }

    private static function validateRegexp(
        Variable $value,
        ?\PHPCompiler\VM\HashTable $filterOptions,
        bool $nullOnFailure = false
    ): Variable {
        if (null === $filterOptions) {
            throw new \ValueError('filter_var(): "regexp" option is missing');
        }
        $regexpVar = $filterOptions->find('regexp');
        if (null === $regexpVar
            || $regexpVar->isUndefined()
            || Variable::TYPE_NULL === $regexpVar->type
            || Variable::TYPE_STRING !== $regexpVar->resolveIndirect()->type) {
            throw new \ValueError('filter_var(): "regexp" option is missing');
        }
        $pattern = $regexpVar->resolveIndirect()->toString();
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $subject = $value->toString();
        $matched = VmPregNative::pregMatch($pattern, $subject);
        if (false === $matched || 0 === $matched) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($subject);

        return $out;
    }

    private static function failureResult(bool $nullOnFailure): Variable
    {
        $out = new Variable();
        if ($nullOnFailure) {
            $out->null();
        } else {
            $out->bool(false);
        }

        return $out;
    }

    private static function validateBoolean(Variable $value, bool $nullOnFailure = false): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_BOOLEAN === $value->type) {
            $out = new Variable();
            $out->bool($value->toBool());

            return $out;
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            $i = $value->toInt();
            if (0 === $i) {
                $out = new Variable();
                $out->bool(false);

                return $out;
            }
            if (1 === $i) {
                $out = new Variable();
                $out->bool(true);

                return $out;
            }

            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            $f = $value->toFloat();
            if (0.0 === $f) {
                $out = new Variable();
                $out->bool(false);

                return $out;
            }
            if (1.0 === $f) {
                $out = new Variable();
                $out->bool(true);

                return $out;
            }

            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $parsed = self::parseBooleanString($value->toString());
        if (null === $parsed) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->bool($parsed);

        return $out;
    }

    private static function validateFloat(
        Variable $value,
        bool $nullOnFailure = false,
        ?\PHPCompiler\VM\HashTable $filterOptions = null
    ): Variable {
        $range = self::parseRangeOptions($filterOptions, true);
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            $f = (float) $value->toInt();
            if (!self::floatInRange($f, $range['min'], $range['max'])) {
                return self::failureResult($nullOnFailure);
            }
            $out = new Variable();
            $out->float($f);

            return $out;
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            $f = $value->toFloat();
            if (!is_finite($f)) {
                return self::failureResult($nullOnFailure);
            }
            if (!self::floatInRange($f, $range['min'], $range['max'])) {
                return self::failureResult($nullOnFailure);
            }
            $out = new Variable();
            $out->float($f);

            return $out;
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $parsed = self::parseFloatString($value->toString());
        if (null === $parsed || !self::floatInRange($parsed, $range['min'], $range['max'])) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->float($parsed);

        return $out;
    }

    /**
     * php-src ext/filter/logical_filters.c — php_filter_boolean (trim + token match).
     */
    public static function parseBooleanString(string $s): ?bool
    {
        $s = trim($s);
        $len = strlen($s);
        if (0 === $len) {
            return false;
        }
        if (1 === $len) {
            if ('1' === $s) {
                return true;
            }
            if ('0' === $s) {
                return false;
            }

            return null;
        }
        if (2 === $len) {
            if (0 === strncasecmp($s, 'on', 2)) {
                return true;
            }
            if (0 === strncasecmp($s, 'no', 2)) {
                return false;
            }

            return null;
        }
        if (3 === $len) {
            if (0 === strncasecmp($s, 'yes', 3)) {
                return true;
            }
            if (0 === strncasecmp($s, 'off', 3)) {
                return false;
            }

            return null;
        }
        if (4 === $len && 0 === strncasecmp($s, 'true', 4)) {
            return true;
        }
        if (5 === $len && 0 === strncasecmp($s, 'false', 5)) {
            return false;
        }

        return null;
    }

    /** php-src ext/filter/logical_filters.c — php_filter_float (trim + numeric parse). */
    public static function parseFloatString(string $s): ?float
    {
        $s = trim($s);
        if ('' === $s) {
            return null;
        }
        if (!preg_match('/^[+-]?((\d+(\.\d*)?)|(\.\d+))([eE][+-]?\d+)?$/', $s)) {
            return null;
        }
        $f = (float) $s;

        return is_finite($f) ? $f : null;
    }

    private static function validateInt(
        Variable $value,
        bool $nullOnFailure = false,
        int $flags = 0,
        ?\PHPCompiler\VM\HashTable $filterOptions = null
    ): Variable {
        $range = self::parseRangeOptions($filterOptions, false);
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            $intVal = $value->toInt();
            if (!self::intInRange($intVal, $range['min'], $range['max'])) {
                return self::failureResult($nullOnFailure);
            }
            $out = new Variable();
            $out->int($intVal);

            return $out;
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        if ('' === $s) {
            return self::failureResult($nullOnFailure);
        }
        $parsed = self::parseIntFilterString($s, $flags);
        if (null === $parsed || !self::intInRange($parsed, $range['min'], $range['max'])) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->int($parsed);

        return $out;
    }

    /**
     * FILTER_VALIDATE_INT string parsing (php-src ext/filter/logical_filters.c).
     */
    public static function parseIntFilterString(string $s, int $flags = 0): ?int
    {
        $allowHex = 0 !== ($flags & self::FILTER_FLAG_ALLOW_HEX);
        $allowOctal = 0 !== ($flags & self::FILTER_FLAG_ALLOW_OCTAL);
        if ($allowHex && self::isHexIntegerString($s)) {
            return self::parseHexIntegerString($s);
        }
        if ($allowOctal && self::isOctalIntegerString($s)) {
            return self::parseOctalIntegerString($s);
        }
        if (!self::isIntegerString($s)) {
            return null;
        }

        return (int) $s;
    }

    private static function validateEmail(Variable $value, bool $nullOnFailure = false, int $flags = 0): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        if (!self::isValidEmailSubset($s, $flags)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /**
     * FILTER_VALIDATE_IP (php-src ext/filter/logical_filters.c — php_filter_validate_ip).
     */
    private static function validateIp(Variable $value, bool $nullOnFailure = false, int $flags = 0): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        if (!self::isValidIpAddress($s, $flags)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /**
     * php-src ext/filter/logical_filters.c — php_filter_validate_ip / _php_parse_ip.
     */
    public static function isValidIpAddress(string $s, int $flags = 0): bool
    {
        if ('' === $s) {
            return false;
        }
        $addr = $s;
        if (str_starts_with($s, '[') && str_ends_with($s, ']')) {
            $addr = substr($s, 1, -1);
        }
        $packed = VmInetPure::inet_pton($addr);
        if (false === $packed) {
            return false;
        }
        $isV4 = 4 === \strlen($packed);
        $isV6 = 16 === \strlen($packed);
        $ipv4Only = 0 !== ($flags & self::FILTER_FLAG_IPV4);
        $ipv6Only = 0 !== ($flags & self::FILTER_FLAG_IPV6);
        if ($ipv4Only && !$ipv6Only) {
            return $isV4;
        }
        if ($ipv6Only && !$ipv4Only) {
            return $isV6;
        }
        if (!$isV4 && !$isV6) {
            return false;
        }

        $noPriv = 0 !== ($flags & self::FILTER_FLAG_NO_PRIV_RANGE);
        $noRes = 0 !== ($flags & self::FILTER_FLAG_NO_RES_RANGE);
        $globalOnly = 0 !== ($flags & self::FILTER_FLAG_GLOBAL_RANGE);
        if ($noPriv || $noRes || $globalOnly) {
            $status = self::ipSpecialStatus($packed);
            if (null !== $status) {
                if ($noPriv && $status['private']) {
                    return false;
                }
                if ($noRes && $status['reserved']) {
                    return false;
                }
                if ($globalOnly && !$status['global']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * RFC 6890 special-purpose status (php-src ext/filter/logical_filters.c).
     *
     * @return array{global: bool, reserved: bool, private: bool}|null null when no special block
     */
    private static function ipSpecialStatus(string $packed): ?array
    {
        if (4 === \strlen($packed)) {
            return self::ipv4SpecialStatus(array_values(unpack('C4', $packed)));
        }
        if (16 === \strlen($packed)) {
            return self::ipv6SpecialStatus(array_values(unpack('n8', $packed)));
        }

        return null;
    }

    /**
     * @param array<int, int> $ip
     *
     * @return array{global: bool, reserved: bool, private: bool}|null
     */
    private static function ipv4SpecialStatus(array $ip): ?array
    {
        $global = false;
        $reserved = false;
        $private = false;

        if (0 === $ip[0]) {
            $reserved = true;
        } elseif (10 === $ip[0]) {
            $private = true;
        } elseif (100 === $ip[0] && $ip[1] >= 64 && $ip[1] <= 127) {
            // RFC 6598 — Shared Address Space
        } elseif (127 === $ip[0]) {
            $reserved = true;
        } elseif (169 === $ip[0] && 254 === $ip[1]) {
            $reserved = true;
        } elseif (172 === $ip[0] && $ip[1] >= 16 && $ip[1] <= 31) {
            $private = true;
        } elseif (192 === $ip[0] && 0 === $ip[1] && 0 === $ip[2]) {
            // RFC 6890 — IETF Protocol Assignments
        } elseif (192 === $ip[0] && 0 === $ip[1] && 0 === $ip[2] && $ip[3] >= 0 && $ip[3] <= 7) {
            // RFC 6333 — DS-Lite
        } elseif (192 === $ip[0] && 0 === $ip[1] && 2 === $ip[2]) {
            // RFC 5737 — Documentation
        } elseif (192 === $ip[0] && 88 === $ip[1] && 99 === $ip[2]) {
            $global = true;
        } elseif (192 === $ip[0] && 168 === $ip[1]) {
            $private = true;
        } elseif (198 === $ip[0] && $ip[1] >= 18 && $ip[1] <= 19) {
            // RFC 2544 — Benchmarking
        } elseif (198 === $ip[0] && 51 === $ip[1] && 100 === $ip[2]) {
            // RFC 5737 — Documentation
        } elseif (203 === $ip[0] && 0 === $ip[1] && 113 === $ip[2]) {
            // RFC 5737 — Documentation
        } elseif ($ip[0] >= 240 && $ip[0] <= 255) {
            $reserved = true;
        } elseif (255 === $ip[0] && 255 === $ip[1] && 255 === $ip[2] && 255 === $ip[3]) {
            $reserved = true;
        } else {
            return null;
        }

        return ['global' => $global, 'reserved' => $reserved, 'private' => $private];
    }

    /**
     * @param array<int, int> $ip eight 16-bit words
     *
     * @return array{global: bool, reserved: bool, private: bool}|null
     */
    private static function ipv6SpecialStatus(array $ip): ?array
    {
        $global = false;
        $reserved = false;
        $private = false;

        if (0 === $ip[0] && 0 === $ip[1] && 0 === $ip[2] && 0 === $ip[3]
            && 0 === $ip[4] && 0 === $ip[5] && 0 === $ip[6] && 0 === $ip[7]) {
            $reserved = true;
        } elseif (0 === $ip[0] && 0 === $ip[1] && 0 === $ip[2] && 0 === $ip[3]
            && 0 === $ip[4] && 0 === $ip[5] && 0 === $ip[6] && 1 === $ip[7]) {
            $reserved = true;
        } elseif (0x0064 === $ip[0] && 0xff9b === $ip[1]) {
            $global = true;
        } elseif (0 === $ip[0] && 0 === $ip[1] && 0 === $ip[2] && 0 === $ip[3]
            && 0 === $ip[4] && 0 === $ip[5] && 0xffff === $ip[6]) {
            $reserved = true;
        } elseif (0x0100 === $ip[0] && 0 === $ip[1] && 0 === $ip[2] && 0 === $ip[3]) {
            // RFC 6666 — Discard-Only
        } elseif (0x2001 === $ip[0] && 0 === $ip[1]) {
            // RFC 4380 — TEREDO
        } elseif (0x2001 === $ip[0] && $ip[1] <= 0x01ff) {
            // RFC 2928 — IETF Protocol Assignments
        } elseif (0x2001 === $ip[0] && 0x0002 === $ip[1] && 0 === $ip[2]) {
            // RFC 5180 — Benchmarking
        } elseif (0x2001 === $ip[0] && 0x0db8 === $ip[1]) {
            // RFC 3849 — Documentation
        } elseif (0x2001 === $ip[0] && $ip[1] >= 0x0010 && $ip[1] <= 0x001f) {
            // RFC 4843 — ORCHID
        } elseif (0x2002 === $ip[0]) {
            // RFC 3056 — 6to4
        } elseif ($ip[0] >= 0xfc00 && $ip[0] <= 0xfdff) {
            $private = true;
        } elseif ($ip[0] >= 0xfe80 && $ip[0] <= 0xfebf) {
            $reserved = true;
        } else {
            return null;
        }

        return ['global' => $global, 'reserved' => $reserved, 'private' => $private];
    }

    private static function validateUrl(Variable $value, bool $nullOnFailure = false, int $flags = 0): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        if (!self::isValidUrlSubset($s, $flags)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /**
     * Practical URL subset for FILTER_VALIDATE_URL (php-src ext/filter/logical_filters.c).
     */
    public static function isValidUrlSubset(string $s, int $flags = 0): bool
    {
        if ('' === $s) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $s)) {
            return false;
        }
        $parsed = VmString::parseUrl($s);
        if (!\is_array($parsed)) {
            return false;
        }
        if (!isset($parsed['scheme']) || '' === $parsed['scheme']) {
            return false;
        }
        $scheme = strtolower($parsed['scheme']);
        $host = $parsed['host'] ?? null;
        if (null === $host || '' === $host) {
            if (!\in_array($scheme, ['mailto', 'news', 'file'], true)) {
                return false;
            }
        } elseif (\in_array($scheme, ['http', 'https'], true) && !self::isValidUrlHost($host)) {
            return false;
        }
        if ((0 !== ($flags & self::FILTER_FLAG_PATH_REQUIRED)) && !isset($parsed['path'])) {
            return false;
        }
        if ((0 !== ($flags & self::FILTER_FLAG_QUERY_REQUIRED)) && !isset($parsed['query'])) {
            return false;
        }

        return true;
    }

    private static function isValidUrlHost(string $host): bool
    {
        if ('' === $host) {
            return false;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return self::isValidIpv6Hostname($host);
        }
        if (self::isValidIpv4Hostname($host)) {
            return true;
        }

        return self::isValidDomainHostname($host);
    }

    private static function isValidIpv4Hostname(string $host): bool
    {
        if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $host)) {
            return false;
        }
        foreach (explode('.', $host) as $octet) {
            if ((int) $octet > 255) {
                return false;
            }
        }

        return true;
    }

    /** php-src ext/filter/logical_filters.c — php_filter_is_valid_ipv6_hostname (subset). */
    private static function isValidIpv6Hostname(string $host): bool
    {
        if (strlen($host) < 3) {
            return false;
        }
        $inner = substr($host, 1, -1);

        return (bool) preg_match('/^[0-9a-fA-F:.]+$/', $inner);
    }

    /** Loose hostname check aligned with php_filter_validate_domain_ex FILTER_FLAG_HOSTNAME subset. */
    private static function isValidDomainHostname(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }
        if ('.' === $host || str_starts_with($host, '.') || str_ends_with($host, '.')) {
            return false;
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if ('' === $label || strlen($label) > 63) {
                return false;
            }
            if ('-' === $label[0] || '-' === $label[strlen($label) - 1]) {
                return false;
            }
            if (!self::charsMatch($label, [self::class, 'isUrlDomainLabelChar'])) {
                return false;
            }
        }

        return true;
    }

    private static function isUrlDomainLabelChar(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9')
            || '-' === $ch;
    }

    public static function isIntegerString(string $s): bool
    {
        if ('' === $s) {
            return false;
        }
        $i = 0;
        $len = strlen($s);
        if ('+' === $s[0] || '-' === $s[0]) {
            if (1 === $len) {
                return false;
            }
            ++$i;
        }
        // php-src ext/filter/logical_filters.c — reject leading zeros (except lone "0").
        if ($len - $i > 1 && '0' === $s[$i]) {
            return false;
        }
        for (; $i < $len; ++$i) {
            $ch = $s[$i];
            if ($ch < '0' || $ch > '9') {
                return false;
            }
        }

        return true;
    }

    public static function isHexIntegerString(string $s): bool
    {
        return (bool) preg_match('/^[+-]?0[xX][0-9a-fA-F]+$/', $s);
    }

    public static function isOctalIntegerString(string $s): bool
    {
        if (!preg_match('/^[+-]?0[0-7]*$/', $s)) {
            return false;
        }
        $body = ltrim($s, '+-');
        if (str_starts_with($body, '0x') || str_starts_with($body, '0X')) {
            return false;
        }

        return true;
    }

    public static function parseHexIntegerString(string $s): int
    {
        $neg = str_starts_with($s, '-');
        $body = ltrim($s, '+-');
        $val = (int) hexdec(substr($body, 2));

        return $neg ? -$val : $val;
    }

    public static function parseOctalIntegerString(string $s): int
    {
        $neg = str_starts_with($s, '-');
        $body = ltrim($s, '+-');
        $val = (int) octdec($body);

        return $neg ? -$val : $val;
    }

    /**
     * Practical email subset: one @, non-empty local/domain, domain has a dot, ASCII only.
     */
    public static function isValidEmailSubset(string $s, int $flags = 0): bool
    {
        $len = strlen($s);
        if (0 === $len || $len > 320) {
            return false;
        }
        $at = strpos($s, '@');
        if (false === $at || $at !== strrpos($s, '@')) {
            return false;
        }
        if (0 === $at || $at === $len - 1) {
            return false;
        }
        $local = substr($s, 0, $at);
        $domain = substr($s, $at + 1);
        if ('' === $local || '' === $domain || !str_contains($domain, '.')) {
            return false;
        }
        $unicode = 0 !== ($flags & self::FILTER_FLAG_EMAIL_UNICODE);
        if (!self::isEmailLocalPart($local, $unicode) || !self::isEmailDomainPart($domain, $unicode)) {
            return false;
        }

        return true;
    }

    private static function isEmailLocalPart(string $local, bool $unicode): bool
    {
        if ($unicode) {
            return (bool) preg_match('/^[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]+$/u', $local);
        }

        return self::charsMatch($local, [self::class, 'isEmailLocalChar']);
    }

    private static function isEmailDomainPart(string $domain, bool $unicode): bool
    {
        if ($unicode) {
            return (bool) preg_match('/^[\p{L}\p{N}.-]+$/u', $domain);
        }

        return self::charsMatch($domain, [self::class, 'isEmailDomainChar']);
    }

    private static function charsMatch(string $s, callable $predicate): bool
    {
        $len = strlen($s);
        for ($i = 0; $i < $len; ++$i) {
            if (!$predicate($s[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function isEmailLocalChar(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9')
            || str_contains('.!#$%&\'*+/=?^_`{|}~-', $ch);
    }

    private static function isEmailDomainChar(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9')
            || '.' === $ch || '-' === $ch;
    }
}
