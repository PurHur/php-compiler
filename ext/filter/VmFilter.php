<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

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
    /** php-src ext/filter/filter_private.h — FILTER_VALIDATE_REGEXP */
    public const FILTER_VALIDATE_REGEXP = 272;
    public const FILTER_VALIDATE_EMAIL = 274;
    /** php-src ext/filter/php_filter.h — PHP_FILTER_FLAG_NULL_ON_FAILURE */
    public const FILTER_NULL_ON_FAILURE = 134217728;
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
            || self::FILTER_VALIDATE_REGEXP === $filter
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
            return self::validateInt($value, $nullOnFailure);
        }
        if (self::FILTER_VALIDATE_REGEXP === $filter) {
            return self::validateRegexp($value, $parsed['filterOptions'], $nullOnFailure);
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
        $matched = @\preg_match($pattern, $subject);
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

    private static function validateInt(Variable $value, bool $nullOnFailure = false): Variable
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
        if ('' === $s || !self::isIntegerString($s)) {
            return self::failureResult($nullOnFailure);
        }
        $out = new Variable();
        $out->int((int) $s);

        return $out;
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
