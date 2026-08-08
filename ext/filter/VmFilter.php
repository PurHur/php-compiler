<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmEngineBuiltinDeprecation;
use PHPCompiler\ext\standard\VmInetPure;
use PHPCompiler\ext\standard\VmPregNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * filter_var() / filter_input() subset (issue #104, #6028, #22852).
 *
 * php-src: ext/filter/filter.c, logical_filters.c, callback_filter.c
 */
final class VmFilter
{
    public const FILTER_FLAG_NONE = 0;
    public const FILTER_REQUIRE_ARRAY = 0x1000000;
    public const FILTER_REQUIRE_SCALAR = 0x2000000;
    public const FILTER_FORCE_ARRAY = 0x4000000;
    public const FILTER_NULL_ON_FAILURE = 0x8000000;
    /** PHP 8.5+ only — shares the pre-8.5 FILTER_FLAG_GLOBAL_RANGE bit (#24065). */
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
    public const FILTER_FLAG_PATH_REQUIRED = 0x040000;
    public const FILTER_FLAG_QUERY_REQUIRED = 0x080000;
    public const FILTER_FLAG_IPV4 = 0x00100000;
    public const FILTER_FLAG_IPV6 = 0x00200000;
    public const FILTER_FLAG_NO_RES_RANGE = 0x00400000;
    public const FILTER_FLAG_NO_PRIV_RANGE = 0x00800000;
    /**
     * PHP ≤8.4 / php-src PHP-8.2 filter_private.h — 0x10000000.
     * PHP 8.5+ relocates this to {@see FILTER_FLAG_GLOBAL_RANGE_PHP85} when THROW takes the bit (#24065).
     */
    public const FILTER_FLAG_GLOBAL_RANGE = 0x10000000;
    /** PHP 8.5+ FILTER_FLAG_GLOBAL_RANGE after FILTER_THROW_ON_FAILURE claimed 0x10000000. */
    public const FILTER_FLAG_GLOBAL_RANGE_PHP85 = 0x20000000;
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

    /**
     * Active FILTER_* flags / identity for {@see failureResult()} THROW path (#28131).
     *
     * @var array{flags: int, filterName: string, valueRepr: string}|null
     */
    private static ?array $failureCtx = null;

    /**
     * Profile-correct FILTER_FLAG_GLOBAL_RANGE bit (php-src filter_private.h; #24065).
     */
    public static function filterFlagGlobalRange(): int
    {
        return CompilerVersion::supportsFilterThrowOnFailure()
            ? self::FILTER_FLAG_GLOBAL_RANGE_PHP85
            : self::FILTER_FLAG_GLOBAL_RANGE;
    }

    /** php-src php_filter_list name for exception messages (#28131). */
    public static function nameForFilterId(int $filter): string
    {
        static $idToName = null;
        if (null === $idToName) {
            $idToName = [];
            foreach (FilterConstants::NAME_TO_ID as $name => $id) {
                if (!isset($idToName[$id])) {
                    $idToName[$id] = $name;
                }
            }
        }

        return $idToName[$filter] ?? 'unknown';
    }

    /**
     * php-src php_filter_call — NULL_ON_FAILURE and THROW_ON_FAILURE are mutually exclusive (#28131).
     */
    public static function assertThrowNullExclusive(int $flags, string $function, int $optionsArgNum): void
    {
        if (!CompilerVersion::supportsFilterThrowOnFailure()) {
            return;
        }
        if (0 === ($flags & self::FILTER_NULL_ON_FAILURE) || 0 === ($flags & self::FILTER_THROW_ON_FAILURE)) {
            return;
        }

        throw new \ValueError(sprintf(
            '%s(): Argument #%d ($options) cannot use both FILTER_NULL_ON_FAILURE and FILTER_THROW_ON_FAILURE',
            $function,
            $optionsArgNum
        ));
    }

    /** Stringify a filter subject for FILTER_THROW_ON_FAILURE messages (php-src copy_for_throwing). */
    public static function valueReprForThrow(Variable $value): string
    {
        $resolved = $value->resolveIndirect();
        if ($resolved->isUndefined() || Variable::TYPE_NULL === $resolved->type) {
            return '';
        }
        $coerced = self::coerceFilterScalarString($resolved);
        if (null !== $coerced) {
            return $coerced;
        }
        if (Variable::TYPE_ARRAY === $resolved->type) {
            return 'Array';
        }
        if (Variable::TYPE_OBJECT === $resolved->type) {
            $obj = $resolved->toObject();
            $class = (null !== $obj->class && '' !== $obj->class->name) ? $obj->class->name : 'stdClass';

            return 'Object('.$class.')';
        }

        try {
            return $resolved->toString();
        } catch (\Throwable) {
            return '';
        }
    }

    public static function isSupportedFilter(int $filter): bool
    {
        return self::isValidateFilter($filter)
            || self::isSanitizeFilter($filter)
            || self::FILTER_CALLBACK === $filter;
    }

    public static function isValidateFilter(int $filter): bool
    {
        return self::FILTER_VALIDATE_INT === $filter
            || self::FILTER_VALIDATE_BOOLEAN === $filter
            || self::FILTER_VALIDATE_FLOAT === $filter
            || self::FILTER_VALIDATE_REGEXP === $filter
            || self::FILTER_VALIDATE_DOMAIN === $filter
            || self::FILTER_VALIDATE_URL === $filter
            || self::FILTER_VALIDATE_EMAIL === $filter
            || self::FILTER_VALIDATE_IP === $filter
            || self::FILTER_VALIDATE_MAC === $filter;
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

    public static function unknownFilterWarningMessage(int $filter, string $function = 'filter_var'): string
    {
        return $function.'(): Unknown filter with ID '.$filter;
    }

    /**
     * php-src php_filter_call / filter_var() (#22852 CALLBACK, #22839 definition flags).
     *
     * @param int $defaultFlags FILTER_REQUIRE_SCALAR for filter_var(); overridden by options flags
     */
    public static function filterVar(
        Variable $value,
        int $filter,
        ?Variable $options = null,
        ?Frame $frame = null,
        int $defaultFlags = self::FILTER_REQUIRE_SCALAR,
        string $function = 'filter_var',
        int $optionsArgNum = 3
    ): Variable {
        if (self::FILTER_CALLBACK === $filter) {
            return self::applyCallbackFilter($value, $options, $frame, $defaultFlags, $function, $optionsArgNum);
        }

        $parsed = self::parseFilterArgs($options);
        $flags = self::normalizeFilterFlags($parsed['flags'], $options, $defaultFlags);
        self::assertThrowNullExclusive($flags, $function, $optionsArgNum);
        $value = $value->resolveIndirect();

        if (Variable::TYPE_ARRAY === $value->type) {
            if (0 !== ($flags & self::FILTER_REQUIRE_SCALAR)) {
                return self::failureResult(
                    0 !== ($flags & self::FILTER_NULL_ON_FAILURE),
                    $flags,
                    'filter validation failed: not a scalar value (got an array)'
                );
            }

            return self::filterVarRecursive($value->toArray(), $filter, $options, $frame, $flags);
        }
        if (0 !== ($flags & self::FILTER_REQUIRE_ARRAY)) {
            return self::failureResult(
                0 !== ($flags & self::FILTER_NULL_ON_FAILURE),
                $flags,
                sprintf(
                    'filter validation failed: not an array (got %s)',
                    self::zendTypeName($value)
                )
            );
        }

        $filtered = self::filterVarScalar($value, $filter, $flags, $parsed['filterOptions']);
        if (0 !== ($flags & self::FILTER_FORCE_ARRAY)) {
            return self::wrapInArray($filtered);
        }

        return $filtered;
    }

    /**
     * Apply a non-callback filter to a scalar (no array / FORCE_ARRAY handling).
     */
    private static function filterVarScalar(
        Variable $value,
        int $filter,
        int $flags,
        ?HashTable $filterOptions
    ): Variable {
        $nullOnFailure = 0 !== ($flags & self::FILTER_NULL_ON_FAILURE);
        $prevCtx = self::$failureCtx;
        self::$failureCtx = [
            'flags' => $flags,
            'filterName' => self::nameForFilterId($filter),
            'valueRepr' => self::valueReprForThrow($value),
        ];
        try {
            if (self::FILTER_VALIDATE_INT === $filter) {
                return self::validateInt($value, $nullOnFailure, $flags, $filterOptions);
            }
            if (self::FILTER_VALIDATE_BOOLEAN === $filter) {
                return self::validateBoolean($value, $nullOnFailure);
            }
            if (self::FILTER_VALIDATE_FLOAT === $filter) {
                return self::validateFloat($value, $nullOnFailure, $filterOptions);
            }
            if (self::FILTER_VALIDATE_REGEXP === $filter) {
                return self::validateRegexp($value, $filterOptions, $nullOnFailure);
            }
            if (self::FILTER_VALIDATE_DOMAIN === $filter) {
                return self::validateDomain($value, $nullOnFailure, $flags);
            }
            if (self::FILTER_VALIDATE_URL === $filter) {
                return self::validateUrl($value, $nullOnFailure, $flags);
            }
            if (self::FILTER_VALIDATE_EMAIL === $filter) {
                return self::validateEmail($value, $nullOnFailure, $flags);
            }
            if (self::FILTER_VALIDATE_IP === $filter) {
                return self::validateIp($value, $nullOnFailure, $flags);
            }
            if (self::FILTER_VALIDATE_MAC === $filter) {
                return self::validateMac($value, $nullOnFailure, $filterOptions);
            }
            if (self::isSanitizeFilter($filter)) {
                return self::sanitize($value, $filter, $flags, $filterOptions);
            }

            return self::failureResult(false, $flags);
        } finally {
            self::$failureCtx = $prevCtx;
        }
    }

    /**
     * php-src php_zval_filter_recursive — map filter over array elements.
     */
    private static function filterVarRecursive(
        HashTable $ht,
        int $filter,
        ?Variable $options,
        ?Frame $frame,
        int $flags
    ): Variable {
        $out = new HashTable();
        $childFlags = $flags & ~self::FILTER_FORCE_ARRAY;
        $parsed = self::parseFilterArgs($options);
        foreach ($ht->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $value = $valueVar->resolveIndirect();
            if (Variable::TYPE_ARRAY === $value->type) {
                if (0 !== ($childFlags & self::FILTER_REQUIRE_SCALAR)) {
                    $filtered = self::failureResult(
                        0 !== ($childFlags & self::FILTER_NULL_ON_FAILURE),
                        $childFlags,
                        'filter validation failed: not a scalar value (got an array)'
                    );
                } else {
                    $filtered = self::filterVarRecursive(
                        $value->toArray(),
                        $filter,
                        $options,
                        $frame,
                        $childFlags
                    );
                }
            } elseif (0 !== ($childFlags & self::FILTER_REQUIRE_ARRAY)) {
                $filtered = self::failureResult(
                    0 !== ($childFlags & self::FILTER_NULL_ON_FAILURE),
                    $childFlags,
                    sprintf(
                        'filter validation failed: not an array (got %s)',
                        self::zendTypeName($value)
                    )
                );
            } else {
                $filtered = self::filterVarScalar($value, $filter, $childFlags, $parsed['filterOptions']);
            }
            self::storeFilteredEntry($out, $keyVar, $filtered);
        }
        $result = new Variable();
        $result->array($out);

        return $result;
    }

    /**
     * FILTER_CALLBACK — php-src ext/filter/callback_filter.c (#22852).
     */
    private static function applyCallbackFilter(
        Variable $value,
        ?Variable $options,
        ?Frame $frame,
        int $defaultFlags,
        string $function = 'filter_var',
        int $optionsArgNum = 3
    ): Variable {
        [$callback, $flags] = self::parseCallbackArgs($options, $defaultFlags);
        self::assertThrowNullExclusive($flags, $function, $optionsArgNum);
        $ctx = null !== $frame ? $frame->vmContext : null;
        if (null === $ctx
            || null === $callback
            || !VmCallable::isCallable($ctx, $callback, false, null, $frame)
        ) {
            throw new \TypeError('filter_var(): Option must be a valid callback');
        }

        return self::invokeCallbackFilter($ctx, $callback, $value, $flags, $frame);
    }

    /**
     * Apply an already-validated FILTER_CALLBACK callable (php_zval_filter_recursive).
     */
    private static function invokeCallbackFilter(
        Context $ctx,
        Variable $callback,
        Variable $value,
        int $flags,
        ?Frame $frame
    ): Variable {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            if (0 !== ($flags & self::FILTER_REQUIRE_SCALAR)) {
                return self::failureResult(
                    0 !== ($flags & self::FILTER_NULL_ON_FAILURE),
                    $flags,
                    'filter validation failed: not a scalar value (got an array)'
                );
            }
            $out = new HashTable();
            $childFlags = $flags & ~self::FILTER_FORCE_ARRAY;
            foreach ($value->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                $filtered = self::invokeCallbackFilter(
                    $ctx,
                    $callback,
                    $valueVar->resolveIndirect(),
                    $childFlags,
                    $frame
                );
                self::storeFilteredEntry($out, $keyVar, $filtered);
            }
            $result = new Variable();
            $result->array($out);

            return $result;
        }
        if (0 !== ($flags & self::FILTER_REQUIRE_ARRAY)) {
            return self::failureResult(
                0 !== ($flags & self::FILTER_NULL_ON_FAILURE),
                $flags,
                sprintf(
                    'filter validation failed: not an array (got %s)',
                    self::zendTypeName($value)
                )
            );
        }

        $arg = new Variable();
        $arg->copyFrom($value);
        $filtered = VmCallable::invoke($ctx, $callback, $arg);
        if (0 !== ($flags & self::FILTER_FORCE_ARRAY)) {
            return self::wrapInArray($filtered);
        }

        return $filtered;
    }

    /**
     * @return array{0: ?Variable, 1: int}
     */
    private static function parseCallbackArgs(?Variable $options, int $defaultFlags): array
    {
        if (null === $options || $options->isUndefined() || Variable::TYPE_NULL === $options->type) {
            return [null, self::normalizeFilterFlags(0, null, $defaultFlags)];
        }
        $resolved = $options->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return [null, self::normalizeFilterFlags($resolved->toInt(), $options, $defaultFlags)];
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \LogicException('filter_var() options must be an integer flag bitmask or array');
        }
        $ht = $resolved->toArray();
        $callback = null;
        $flags = $defaultFlags;
        $optionsVar = $ht->find('options');
        if (null !== $optionsVar && !$optionsVar->isUndefined() && Variable::TYPE_NULL !== $optionsVar->type) {
            // php-src: FILTER_CALLBACK options value is the callable; clears inherited flags.
            $callback = $optionsVar->resolveIndirect();
            $flags = 0;
        }
        $flagsVar = $ht->find('flags');
        if (null !== $flagsVar && !$flagsVar->isUndefined() && Variable::TYPE_NULL !== $flagsVar->type) {
            if (Variable::TYPE_INTEGER !== $flagsVar->resolveIndirect()->type) {
                throw new \LogicException('filter_var() options[flags] must be an integer');
            }
            $flags = $flagsVar->resolveIndirect()->toInt();
            if (0 === ($flags & self::FILTER_REQUIRE_ARRAY) && 0 === ($flags & self::FILTER_FORCE_ARRAY)) {
                $flags |= self::FILTER_REQUIRE_SCALAR;
            }
        }

        return [$callback, $flags];
    }

    /**
     * When options is an int bitmask or absent, OR REQUIRE_SCALAR unless FORCE/REQUIRE_ARRAY.
     * When options is an array without a flags key, keep $defaultFlags (php-src php_filter_call).
     */
    private static function normalizeFilterFlags(int $flags, ?Variable $options, int $defaultFlags): int
    {
        if (null === $options || $options->isUndefined() || Variable::TYPE_NULL === $options->type) {
            $flags = $defaultFlags;
            if (0 === ($flags & self::FILTER_REQUIRE_ARRAY) && 0 === ($flags & self::FILTER_FORCE_ARRAY)) {
                $flags |= self::FILTER_REQUIRE_SCALAR;
            }

            return $flags;
        }
        $resolved = $options->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $flags = $resolved->toInt();
            if (0 === ($flags & self::FILTER_REQUIRE_ARRAY) && 0 === ($flags & self::FILTER_FORCE_ARRAY)) {
                $flags |= self::FILTER_REQUIRE_SCALAR;
            }

            return $flags;
        }
        if (Variable::TYPE_ARRAY === $resolved->type) {
            $flagsVar = $resolved->toArray()->find('flags');
            if (null === $flagsVar || $flagsVar->isUndefined() || Variable::TYPE_NULL === $flagsVar->type) {
                return $defaultFlags;
            }
            if (Variable::TYPE_INTEGER !== $flagsVar->resolveIndirect()->type) {
                throw new \LogicException('filter_var() options[flags] must be an integer');
            }
            $flags = $flagsVar->resolveIndirect()->toInt();
            if (0 === ($flags & self::FILTER_REQUIRE_ARRAY) && 0 === ($flags & self::FILTER_FORCE_ARRAY)) {
                $flags |= self::FILTER_REQUIRE_SCALAR;
            }

            return $flags;
        }

        return $flags;
    }

    private static function wrapInArray(Variable $filtered): Variable
    {
        $ht = new HashTable();
        $stored = new Variable();
        $stored->copyFrom($filtered);
        $ht->addIndex(0, $stored);
        $out = new Variable();
        $out->array($ht);

        return $out;
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
        if (self::FILTER_SANITIZE_STRING === $filter) {
            VmEngineBuiltinDeprecation::emitConstant(null, 'FILTER_SANITIZE_STRING');
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
            self::FILTER_SANITIZE_NUMBER_FLOAT => self::sanitizeNumberFloat($subject, $flags),
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
            self::FILTER_SANITIZE_NUMBER_FLOAT => self::sanitizeNumberFloat($subject, $flags),
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

    /** php-src ext/filter/sanitizing_filters.c — php_filter_number_float */
    private static function sanitizeNumberFloat(string $subject, int $flags = 0): string
    {
        $extra = '';
        if (0 !== ($flags & self::FILTER_FLAG_ALLOW_FRACTION)) {
            $extra .= '.';
        }
        if (0 !== ($flags & self::FILTER_FLAG_ALLOW_THOUSAND)) {
            $extra .= ',';
        }
        if (0 !== ($flags & self::FILTER_FLAG_ALLOW_SCIENTIFIC)) {
            $extra .= 'eE';
        }

        return preg_replace('/[^0-9+\-'.preg_quote($extra, '/').']+/', '', $subject) ?? '';
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

    /**
     * Z_PARAM_LONG $filter — null coerces to 0 on default profile (ext/filter/filter.c; #18943).
     */
    public static function parseFilterIdArg(
        Frame $frame,
        int $argIndex,
        string $function,
        string $paramName,
        int $userArgIndex
    ): int {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireBuiltinTypedInt($frame, $argIndex, $function, $paramName)->toInt();
        }
        $resolved = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return 0;
        }
        $given = match ($resolved->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $resolved->toObject()->class->name,
            default => 'mixed',
        };

        throw new \TypeError(
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $userArgIndex,
                $paramName,
                $given
            )
        );
    }

    public static function resolveInputType(Variable $var, string $fn): int
    {
        $var = $var->resolveIndirect();
        $fromEnum = self::tryPhpInputFilterInt($var);
        if (null !== $fromEnum) {
            self::assertValidInputType($fromEnum, $fn);

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
            $type = $var->toInt();
            self::assertValidInputType($type, $fn);

            return $type;
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

    public static function assertValidInputType(int $type, string $fn): void
    {
        if (!\in_array($type, [
            self::INPUT_POST,
            self::INPUT_GET,
            self::INPUT_COOKIE,
            self::INPUT_ENV,
            self::INPUT_SERVER,
        ], true)) {
            // php-src php_filter_get_storage — ValueError when type is not an INPUT_* constant.
            $arg = 'filter_has_var' === $fn ? 'input_type' : 'type';
            throw new \ValueError(sprintf(
                '%s(): Argument #1 ($%s) must be an INPUT_* constant',
                $fn,
                $arg
            ));
        }
    }

    public static function inputSuperglobalName(int $type): string
    {
        return match ($type) {
            self::INPUT_GET => '_GET',
            self::INPUT_POST => '_POST',
            self::INPUT_COOKIE => '_COOKIE',
            self::INPUT_ENV => '_ENV',
            self::INPUT_SERVER => '_SERVER',
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
        } elseif (self::arrayLooksLikeBareFilterOptions($ht)) {
            // Named-parameter lowering may pass the inner options hash directly (#4404, #5020).
            $filterOptions = $ht;
        }

        return ['flags' => $flags, 'filterOptions' => $filterOptions];
    }

    private static function arrayLooksLikeBareFilterOptions(\PHPCompiler\VM\HashTable $ht): bool
    {
        foreach (['regexp', 'min_range', 'max_range', 'default', 'decimal'] as $key) {
            $var = $ht->find($key);
            if (null !== $var && !$var->isUndefined() && Variable::TYPE_NULL !== $var->type) {
                return true;
            }
        }

        return false;
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

    private static function failureResult(
        bool $nullOnFailure,
        ?int $flags = null,
        ?string $explicitMessage = null
    ): Variable {
        $effectiveFlags = $flags;
        if (null === $effectiveFlags && null !== self::$failureCtx) {
            $effectiveFlags = self::$failureCtx['flags'];
        }
        if (null !== $effectiveFlags
            && CompilerVersion::supportsFilterThrowOnFailure()
            && 0 !== ($effectiveFlags & self::FILTER_THROW_ON_FAILURE)
        ) {
            if (null !== $explicitMessage) {
                throw new \Filter\FilterFailedException($explicitMessage);
            }
            $name = self::$failureCtx['filterName'] ?? 'unknown';
            $repr = self::$failureCtx['valueRepr'] ?? '';
            throw new \Filter\FilterFailedException(sprintf(
                "filter validation failed: filter %s not satisfied by '%s'",
                $name,
                $repr
            ));
        }
        $out = new Variable();
        if ($nullOnFailure) {
            $out->null();
        } else {
            $out->bool(false);
        }

        return $out;
    }

    /** php-src zend_zval_type_name subset for FILTER_THROW messages. */
    private static function zendTypeName(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'boolean',
            Variable::TYPE_INTEGER => 'integer',
            Variable::TYPE_FLOAT => 'double',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'unknown type',
        };
    }

    private static function validateBoolean(Variable $value, bool $nullOnFailure = false): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            // php-src coerces null/undefined to "" before boolean validation (#17238).
            $out = new Variable();
            $out->bool(false);

            return $out;
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
     * FILTER_VALIDATE_INT string parsing (php-src ext/filter/logical_filters.c — php_filter_int).
     * Trims leading/trailing whitespace before hex/octal/decimal parse (#21962).
     */
    public static function parseIntFilterString(string $s, int $flags = 0): ?int
    {
        $s = trim($s);
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

        return self::parseDecimalIntegerString($s);
    }

    /**
     * Decimal string → native int when in [PHP_INT_MIN, PHP_INT_MAX] (php-src ext/filter/logical_filters.c).
     */
    public static function parseDecimalIntegerString(string $s): ?int
    {
        $negative = str_starts_with($s, '-');
        $digits = ltrim($s, '+-');
        $limit = $negative
            ? self::incrementDecimalString((string) \PHP_INT_MAX)
            : (string) \PHP_INT_MAX;
        $len = \strlen($digits);
        $limitLen = \strlen($limit);
        if ($len > $limitLen || ($len === $limitLen && $digits > $limit)) {
            return null;
        }
        if ($negative) {
            if ($digits === $limit) {
                return \PHP_INT_MIN;
            }

            return -((int) $digits);
        }

        return (int) $digits;
    }

    private static function incrementDecimalString(string $digits): string
    {
        $carry = 1;
        $out = '';
        for ($i = \strlen($digits) - 1; $i >= 0; --$i) {
            $sum = (int) $digits[$i] + $carry;
            $out = (string) ($sum % 10).$out;
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $out = (string) $carry.$out;
        }

        return $out;
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
        if (!FilterEmailValidate::isValid($s, $flags)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /**
     * FILTER_VALIDATE_MAC (php-src ext/filter/logical_filters.c — php_filter_validate_mac).
     */
    private static function validateMac(
        Variable $value,
        bool $nullOnFailure = false,
        ?\PHPCompiler\VM\HashTable $filterOptions = null
    ): Variable {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        $expectedSeparator = null;
        if (null !== $filterOptions) {
            $sepVar = $filterOptions->find('separator');
            if (null !== $sepVar && !$sepVar->isUndefined() && Variable::TYPE_NULL !== $sepVar->type) {
                $resolved = $sepVar->resolveIndirect();
                if (Variable::TYPE_STRING !== $resolved->type) {
                    return self::failureResult($nullOnFailure);
                }
                $expectedSeparator = $resolved->toString();
                if (1 !== \strlen($expectedSeparator)) {
                    throw new \ValueError('filter_var(): "separator" option must be one character long');
                }
            }
        }
        if (!self::isValidMacAddress($s, $expectedSeparator)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /**
     * FILTER_VALIDATE_DOMAIN (php-src ext/filter/logical_filters.c — php_filter_validate_domain[_ex]).
     */
    private static function validateDomain(Variable $value, bool $nullOnFailure = false, int $flags = 0): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        if (!self::isValidDomain($s, $flags)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /**
     * php-src ext/filter/logical_filters.c — php_filter_validate_mac.
     */
    public static function isValidMacAddress(string $input, ?string $expectedSeparator = null): bool
    {
        $inputLen = \strlen($input);
        if (14 === $inputLen) {
            $tokens = 3;
            $length = 4;
            $separator = '.';
        } elseif (17 === $inputLen && '-' === $input[2]) {
            $tokens = 6;
            $length = 2;
            $separator = '-';
        } elseif (17 === $inputLen && ':' === $input[2]) {
            $tokens = 6;
            $length = 2;
            $separator = ':';
        } else {
            return false;
        }
        if (null !== $expectedSeparator && $separator !== $expectedSeparator) {
            return false;
        }
        for ($i = 0; $i < $tokens; ++$i) {
            $offset = $i * ($length + 1);
            if ($i < $tokens - 1 && $input[$offset + $length] !== $separator) {
                return false;
            }
            if (!self::isValidHexToken(substr($input, $offset, $length))) {
                return false;
            }
        }

        return true;
    }

    private static function isValidHexToken(string $token): bool
    {
        $len = \strlen($token);
        if (0 === $len) {
            return false;
        }
        for ($i = 0; $i < $len; ++$i) {
            $ch = $token[$i];
            if (!(($ch >= '0' && $ch <= '9') || ($ch >= 'a' && $ch <= 'f') || ($ch >= 'A' && $ch <= 'F'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * SSOT used by JIT/AOT helper bridge.
     *
     * php-src ext/filter/logical_filters.c — php_filter_validate_domain_ex.
     * Plain mode is permissive (allows `_`, leading/trailing `-` in labels, etc.);
     * FILTER_FLAG_HOSTNAME applies hostname label rules.
     */
    public static function isValidDomain(string $host, int $flags = 0): bool
    {
        $hostname = 0 !== ($flags & self::FILTER_FLAG_HOSTNAME);
        $len = \strlen($host);
        $end = $len;

        // Ignore trailing dot for length / scan bound (char still peekable past $end).
        if ($len > 0 && '.' === $host[$len - 1]) {
            $end = $len - 1;
            $len = $end;
        }

        if ($len > 253) {
            return false;
        }

        // "" → success in loose mode; "." (stripped to empty) → fail via first-char '.'.
        if (0 === $len) {
            return 0 === \strlen($host) && !$hostname;
        }

        $first = $host[0];
        if ('.' === $first || ($hostname && !self::isAlnumByte($first))) {
            return false;
        }

        $labelLen = 1;
        for ($s = 0; $s < $end; ++$s) {
            $ch = $host[$s];
            $next = ($s + 1 < \strlen($host)) ? $host[$s + 1] : "\0";
            if ('.' === $ch) {
                // Reject ".." and (HOSTNAME) labels that do not start/end alnum.
                if ('.' === $next
                    || ($hostname && (
                        !self::isAlnumByte($host[$s - 1])
                        || !self::isAlnumByte($next)
                    ))) {
                    return false;
                }
                $labelLen = 1;
                continue;
            }

            // Label length > 63, or (HOSTNAME) char not alnum/`-` (hyphen not at NUL).
            if ($labelLen > 63
                || ($hostname
                    && ('-' !== $ch || "\0" === $next)
                    && !self::isAlnumByte($ch))) {
                return false;
            }
            ++$labelLen;
        }

        return true;
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
        $globalOnly = 0 !== ($flags & self::filterFlagGlobalRange());
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
     * php_filter_validate_url runs php_filter_url first and fails if any byte is stripped (#28996).
     */
    public static function isValidUrlSubset(string $s, int $flags = 0): bool
    {
        if ('' === $s) {
            return false;
        }
        if (0 === FilterUrlValidate::isUrlCharsetOk($s)) {
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

        return self::isValidDomain($host, self::FILTER_FLAG_HOSTNAME);
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

    /** php-src isalnum((unsigned char)ch) for domain label checks. */
    private static function isAlnumByte(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9');
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
     *
     * SSOT: {@see FilterEmailValidate::isValid()} (Nested JIT/AOT unit).
     */
    public static function isValidEmailSubset(string $s, int $flags = 0): bool
    {
        return FilterEmailValidate::isValid($s, $flags);
    }

    /** filter_has_var() — key present in request-input snapshot (php-src IF_G; #3294, #19640). */
    public static function hasInputVar(Context $ctx, int $type, string $key): bool
    {
        $ht = self::requestInputTable($ctx, $type);
        if (null === $ht) {
            return false;
        }
        $keyVar = new Variable();
        $keyVar->string($key);

        return $ht->offsetIsSet($keyVar);
    }

    /**
     * filter_input_array() — batch filter from request-input snapshot (#3294, #19640, #21937).
     *
     * @param HashTable|int|null $definition
     *
     * @return HashTable|null null when the input snapshot is missing
     */
    public static function filterInputArray(
        Context $ctx,
        int $type,
        HashTable|int|null $definition,
        int $addEmpty,
        Frame $frame
    ): ?HashTable {
        $ht = self::requestInputTable($ctx, $type);
        if (null === $ht) {
            return null;
        }

        return self::filterVarArray($ht, $definition, $addEmpty, $frame);
    }

    /**
     * Original request INPUT_* table (not live $_GET/$_POST) (#19640).
     */
    public static function requestInputTable(Context $ctx, int $type): ?HashTable
    {
        return $ctx->getFilterInputSnapshot(self::inputSuperglobalName($type));
    }

    /**
     * filter_input() value lookup from request-input snapshot (#19640).
     */
    public static function requestInputValue(Context $ctx, int $type, string $key): ?Variable
    {
        $ht = self::requestInputTable($ctx, $type);
        if (null === $ht) {
            return null;
        }
        $keyVar = new Variable();
        $keyVar->string($key);
        if (!$ht->offsetIsSet($keyVar)) {
            return null;
        }
        $stored = $ht->find($key);
        if (null === $stored) {
            return null;
        }

        return $stored->resolveIndirect();
    }

    /**
     * filter_var_array() — batch filter_var() over keys (#3294, #21937).
     *
     * php-src: `$options` may be `array|int` — an int filter ID applies to every element.
     *
     * @param HashTable|int|null $definition
     *
     * @return HashTable|null false-equivalent when definition is null and FILTER_DEFAULT fails
     */
    public static function filterVarArray(
        HashTable $data,
        HashTable|int|null $definition,
        int $addEmpty,
        Frame $frame
    ): ?HashTable {
        if (null === $definition) {
            return self::filterVarArrayWithDefaultFilter($data, $frame);
        }
        if (\is_int($definition)) {
            return self::filterVarArrayWithSingleFilter($data, $definition, $frame);
        }

        return self::filterVarArrayWithDefinition($data, $definition, $addEmpty, $frame);
    }

    /** php-src php_filter_var_array — no definition uses FILTER_DEFAULT for every element. */
    private static function filterVarArrayWithDefaultFilter(HashTable $data, Frame $frame): ?HashTable
    {
        return self::filterVarArrayWithSingleFilter($data, self::FILTER_DEFAULT, $frame);
    }

    /**
     * php-src: when `$options` is a long filter ID, apply it to every element of `$data`.
     */
    private static function filterVarArrayWithSingleFilter(
        HashTable $data,
        int $filterId,
        Frame $frame
    ): ?HashTable {
        if (!self::isSupportedFilter($filterId)) {
            filter_var::triggerUnknownFilterWarning($frame, $filterId);

            return null;
        }
        $out = new HashTable();
        foreach ($data->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $filtered = self::filterVar($valueVar->resolveIndirect(), $filterId, null, $frame);
            self::storeFilteredEntry($out, $keyVar, $filtered);
        }

        return $out;
    }

    /**
     * php-src php_filter_array_handler — definition values are int filter IDs or
     * arrays with filter/flags/options keys (#22839, #22852).
     */
    private static function filterVarArrayWithDefinition(
        HashTable $data,
        HashTable $definition,
        int $addEmpty,
        Frame $frame
    ): HashTable {
        $out = new HashTable();
        foreach ($definition->iterateKeyed(true) as [$defKeyVar, $filterVar]) {
            $defKey = $defKeyVar->resolveIndirect();
            $filterResolved = $filterVar->resolveIndirect();
            $filterId = self::FILTER_DEFAULT;
            $optionsVar = null;
            if (Variable::TYPE_INTEGER === $filterResolved->type) {
                $filterId = $filterResolved->toInt();
            } elseif (Variable::TYPE_ARRAY === $filterResolved->type) {
                // Whole definition array is php_filter_call's filter_args_ht.
                $optionsVar = $filterResolved;
                $defHt = $filterResolved->toArray();
                $filterOpt = $defHt->find('filter');
                if (null !== $filterOpt && !$filterOpt->isUndefined() && Variable::TYPE_NULL !== $filterOpt->type) {
                    $filterIdVar = $filterOpt->resolveIndirect();
                    if (Variable::TYPE_INTEGER !== $filterIdVar->type) {
                        throw new \LogicException('filter_var_array() definition filter must be an integer');
                    }
                    $filterId = $filterIdVar->toInt();
                }
            } else {
                throw new \LogicException('filter_var_array() definition values must be filter IDs');
            }
            if (!self::isSupportedFilter($filterId)) {
                filter_var::triggerUnknownFilterWarning($frame, $filterId);
            }
            $stored = self::lookupDataValue($data, $defKey);
            if (null === $stored) {
                if (0 !== $addEmpty) {
                    self::storeFilteredEntry($out, $defKeyVar, self::failureResult(false));
                }
                continue;
            }
            $filtered = self::filterVar(
                $stored->resolveIndirect(),
                $filterId,
                $optionsVar,
                $frame,
                self::FILTER_REQUIRE_SCALAR,
                'filter_var_array',
                2
            );
            self::storeFilteredEntry($out, $defKeyVar, $filtered);
        }

        return $out;
    }

    private static function lookupDataValue(HashTable $data, Variable $key): ?Variable
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return $data->findIndex($key->toInt());
        }
        if (Variable::TYPE_STRING === $key->type) {
            return $data->find($key->toString());
        }

        return null;
    }

    private static function storeFilteredEntry(HashTable $out, Variable $keyVar, Variable $filtered): void
    {
        $stored = new Variable();
        $stored->copyFrom($filtered);
        $key = $keyVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->addIndex($key->toInt(), $stored);

            return;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $out->add($key->toString(), $stored);

            return;
        }
        throw new \LogicException('filter_var_array() only supports string or integer keys');
    }
}
