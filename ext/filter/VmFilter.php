<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

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
    public const FILTER_VALIDATE_INT = 257;
    /** php-src ext/filter/php_filter.h — FILTER_VALIDATE_BOOLEAN */
    public const FILTER_VALIDATE_BOOLEAN = 258;
    /** php-src ext/filter/php_filter.h — FILTER_VALIDATE_FLOAT */
    public const FILTER_VALIDATE_FLOAT = 259;
    /** php-src ext/filter/filter_private.h — FILTER_VALIDATE_REGEXP */
    public const FILTER_VALIDATE_REGEXP = 272;
    /** php-src ext/filter/php_filter.h — FILTER_VALIDATE_URL */
    public const FILTER_VALIDATE_URL = 273;
    public const FILTER_VALIDATE_EMAIL = 274;
    /** php-src ext/filter/php_filter.h — PHP_FILTER_FLAG_NULL_ON_FAILURE */
    public const FILTER_NULL_ON_FAILURE = 134217728;
    /** php-src ext/filter/php_filter.h — FILTER_FLAG_ALLOW_OCTAL */
    public const FILTER_FLAG_ALLOW_OCTAL = 8;
    /** php-src ext/filter/php_filter.h — FILTER_FLAG_ALLOW_HEX */
    public const FILTER_FLAG_ALLOW_HEX = 16;
    /** php-src ext/filter/php_filter.h */
    public const INPUT_POST = 0;

    public const INPUT_GET = 1;

    public const INPUT_COOKIE = 2;

    public const INPUT_ENV = 4;

    public const INPUT_SERVER = 5;

    public const INPUT_SESSION = 6;

    public static function isSupportedFilter(int $filter): bool
    {
        return self::FILTER_VALIDATE_INT === $filter
            || self::FILTER_VALIDATE_BOOLEAN === $filter
            || self::FILTER_VALIDATE_FLOAT === $filter
            || self::FILTER_VALIDATE_REGEXP === $filter
            || self::FILTER_VALIDATE_URL === $filter
            || self::FILTER_VALIDATE_EMAIL === $filter;
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
            return self::validateInt($value, $nullOnFailure, $parsed['flags']);
        }
        if (self::FILTER_VALIDATE_BOOLEAN === $filter) {
            return self::validateBoolean($value, $nullOnFailure);
        }
        if (self::FILTER_VALIDATE_FLOAT === $filter) {
            return self::validateFloat($value, $nullOnFailure);
        }
        if (self::FILTER_VALIDATE_REGEXP === $filter) {
            return self::validateRegexp($value, $parsed['filterOptions'], $nullOnFailure);
        }
        if (self::FILTER_VALIDATE_URL === $filter) {
            return self::validateUrl($value, $nullOnFailure);
        }
        if (self::FILTER_VALIDATE_EMAIL === $filter) {
            return self::validateEmail($value, $nullOnFailure);
        }

        return self::failureResult(false);
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

    private static function validateFloat(Variable $value, bool $nullOnFailure = false): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            $out = new Variable();
            $out->float((float) $value->toInt());

            return $out;
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            $f = $value->toFloat();
            if (!is_finite($f)) {
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
        if (null === $parsed) {
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

    private static function validateInt(Variable $value, bool $nullOnFailure = false, int $flags = 0): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            $out = new Variable();
            $out->int($value->toInt());

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
        if (null === $parsed) {
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

    private static function validateEmail(Variable $value, bool $nullOnFailure = false): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        if (!self::isValidEmailSubset($s)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    private static function validateUrl(Variable $value, bool $nullOnFailure = false): Variable
    {
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return self::failureResult($nullOnFailure);
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return self::failureResult($nullOnFailure);
        }
        $s = $value->toString();
        if (!self::isValidUrlSubset($s)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->string($s);

        return $out;
    }

    /**
     * Practical URL subset for FILTER_VALIDATE_URL (php-src ext/filter/logical_filters.c).
     */
    public static function isValidUrlSubset(string $s): bool
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
            return \in_array($scheme, ['mailto', 'news', 'file'], true);
        }
        if (\in_array($scheme, ['http', 'https'], true) && !self::isValidUrlHost($host)) {
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
    public static function isValidEmailSubset(string $s): bool
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
        if (!self::isEmailLocalPart($local) || !self::isEmailDomainPart($domain)) {
            return false;
        }

        return true;
    }

    private static function isEmailLocalPart(string $local): bool
    {
        return self::charsMatch($local, [self::class, 'isEmailLocalChar']);
    }

    private static function isEmailDomainPart(string $domain): bool
    {
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
