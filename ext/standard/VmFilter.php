<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * filter_var() / filter_input() subset (issue #104) — no PHP filter extension.
 */
final class VmFilter
{
    public const FILTER_VALIDATE_INT = 257;
    public const FILTER_VALIDATE_EMAIL = 274;
    public const INPUT_POST = 0;
    public const INPUT_GET = 1;

    public static function filterVar(Variable $value, int $filter, ?Variable $options = null): Variable
    {
        if (self::FILTER_VALIDATE_INT === $filter) {
            return self::validateInt($value);
        }
        if (self::FILTER_VALIDATE_EMAIL === $filter) {
            return self::validateEmail($value);
        }

        throw new \LogicException(
            'filter_var() filter '.$filter.' is not supported in this compiler build'
        );
    }

    public static function inputSuperglobalName(int $type): string
    {
        if (self::INPUT_GET === $type) {
            return '_GET';
        }
        if (self::INPUT_POST === $type) {
            return '_POST';
        }

        throw new \LogicException(
            'filter_input() type '.$type.' is not supported in this compiler build'
        );
    }

    private static function validateInt(Variable $value): Variable
    {
        $out = new Variable();
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            $out->bool(false);

            return $out;
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            $out->int($value->toInt());

            return $out;
        }
        if (Variable::TYPE_STRING !== $value->type) {
            $out->bool(false);

            return $out;
        }
        $s = $value->toString();
        if ('' === $s || !self::isIntegerString($s)) {
            $out->bool(false);

            return $out;
        }
        $out->int((int) $s);

        return $out;
    }

    private static function validateEmail(Variable $value): Variable
    {
        $out = new Variable();
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            $out->bool(false);

            return $out;
        }
        if (Variable::TYPE_STRING !== $value->type) {
            $out->bool(false);

            return $out;
        }
        $s = $value->toString();
        if (!self::isValidEmailSubset($s)) {
            $out->bool(false);

            return $out;
        }
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
