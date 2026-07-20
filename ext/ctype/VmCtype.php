<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Locale-independent ASCII ctype classification (php-src ext/ctype/ctype.c; #7253, #19717, #20611).
 *
 * Stubs take {@code mixed $text} ({@code Z_PARAM_ZVAL}); null and other non-strings go through
 * {@code ctype_fallback()} — {@code E_DEPRECATED} then {@code false} / int ordinal. Never TypeError
 * for null (reverts mistaken #20252 Z_PARAM_STR mapping; see #20611).
 */
final class VmCtype
{
    public const KIND_ALNUM = 0;
    public const KIND_ALPHA = 1;
    public const KIND_CNTRL = 2;
    public const KIND_DIGIT = 3;
    public const KIND_LOWER = 4;
    public const KIND_GRAPH = 5;
    public const KIND_PRINT = 6;
    public const KIND_PUNCT = 7;
    public const KIND_SPACE = 8;
    public const KIND_UPPER = 9;
    public const KIND_XDIGIT = 10;

    public static function evaluate(
        Variable $var,
        string $function,
        int $kind,
        bool $allowDigits = false,
        bool $allowMinus = false,
        ?Frame $frame = null
    ): bool {
        $var = $var->resolveIndirect();

        // php-src ctype_impl: strings only; everything else is ctype_fallback (#19717, #20611).
        if (Variable::TYPE_STRING === $var->type) {
            return self::checkString($var->toString(), $kind);
        }

        self::emitFallbackDeprecation($function, $var);
        if (Variable::TYPE_INTEGER === $var->type) {
            return self::checkInt($var->toInt(), $kind, $allowDigits, $allowMinus);
        }

        return false;
    }

    /**
     * php-src ctype_fallback(): E_DEPRECATED then long-as-byte / else false.
     *
     * Message: "{fn}(): Argument of type %s will be interpreted as string in the future"
     */
    public static function emitFallbackDeprecation(string $function, Variable $var): void
    {
        $typeName = EnumCaseSupport::typeNameForVariable($var);
        self::emitFallbackDeprecationForTypeName($function, $typeName);
    }

    public static function emitFallbackDeprecationForTypeName(string $function, string $typeName): void
    {
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        $frame = $vm->builtinHandlerFrame();
        if (null === $frame) {
            $frames = $vm->context->runStackFrames();
            $frame = [] !== $frames ? $frames[0] : null;
        }
        $vm->context->errors->internalDeprecated(
            sprintf(
                '%s(): Argument of type %s will be interpreted as string in the future',
                $function,
                $typeName
            ),
            $vm->context,
            $frame
        );
    }

    public static function checkString(string $text, int $kind): bool
    {
        $len = \strlen($text);
        if (0 === $len) {
            return false;
        }
        for ($i = 0; $i < $len; ++$i) {
            if (!self::checkByte(\ord($text[$i]), $kind)) {
                return false;
            }
        }

        return true;
    }

    public static function checkInt(int $value, int $kind, bool $allowDigits, bool $allowMinus): bool
    {
        if ($value >= 0 && $value <= 255) {
            return self::checkByte($value, $kind);
        }
        if ($value >= -128 && $value < 0) {
            return self::checkByte($value + 256, $kind);
        }
        if ($value >= 0) {
            return $allowDigits;
        }

        return $allowMinus;
    }

    public static function checkByte(int $byte, int $kind): bool
    {
        $byte &= 0xff;

        return match ($kind) {
            self::KIND_ALNUM => self::isAlnum($byte),
            self::KIND_ALPHA => self::isAlpha($byte),
            self::KIND_CNTRL => self::isCntrl($byte),
            self::KIND_DIGIT => self::isDigit($byte),
            self::KIND_LOWER => self::isLower($byte),
            self::KIND_GRAPH => self::isGraph($byte),
            self::KIND_PRINT => self::isPrint($byte),
            self::KIND_PUNCT => self::isPunct($byte),
            self::KIND_SPACE => self::isSpace($byte),
            self::KIND_UPPER => self::isUpper($byte),
            self::KIND_XDIGIT => self::isXdigit($byte),
            default => throw new \LogicException('Unknown ctype kind: '.$kind),
        };
    }

    /**
     * @return array{kind: int, allow_digits: bool, allow_minus: bool}
     */
    public static function specForFunction(string $function): array
    {
        return match ($function) {
            'ctype_alnum' => ['kind' => self::KIND_ALNUM, 'allow_digits' => true, 'allow_minus' => false],
            'ctype_alpha' => ['kind' => self::KIND_ALPHA, 'allow_digits' => false, 'allow_minus' => false],
            'ctype_cntrl' => ['kind' => self::KIND_CNTRL, 'allow_digits' => false, 'allow_minus' => false],
            'ctype_digit' => ['kind' => self::KIND_DIGIT, 'allow_digits' => true, 'allow_minus' => false],
            'ctype_lower' => ['kind' => self::KIND_LOWER, 'allow_digits' => false, 'allow_minus' => false],
            'ctype_graph' => ['kind' => self::KIND_GRAPH, 'allow_digits' => true, 'allow_minus' => true],
            'ctype_print' => ['kind' => self::KIND_PRINT, 'allow_digits' => true, 'allow_minus' => true],
            'ctype_punct' => ['kind' => self::KIND_PUNCT, 'allow_digits' => false, 'allow_minus' => false],
            'ctype_space' => ['kind' => self::KIND_SPACE, 'allow_digits' => false, 'allow_minus' => false],
            'ctype_upper' => ['kind' => self::KIND_UPPER, 'allow_digits' => false, 'allow_minus' => false],
            'ctype_xdigit' => ['kind' => self::KIND_XDIGIT, 'allow_digits' => true, 'allow_minus' => false],
            default => throw new \LogicException('Unknown ctype function: '.$function),
        };
    }

    private static function isAlnum(int $byte): bool
    {
        return self::isDigit($byte) || self::isAlpha($byte);
    }

    private static function isAlpha(int $byte): bool
    {
        return self::isLower($byte) || self::isUpper($byte);
    }

    private static function isDigit(int $byte): bool
    {
        return $byte >= 48 && $byte <= 57;
    }

    private static function isLower(int $byte): bool
    {
        return $byte >= 97 && $byte <= 122;
    }

    private static function isUpper(int $byte): bool
    {
        return $byte >= 65 && $byte <= 90;
    }

    private static function isXdigit(int $byte): bool
    {
        return self::isDigit($byte)
            || ($byte >= 65 && $byte <= 70)
            || ($byte >= 97 && $byte <= 102);
    }

    private static function isCntrl(int $byte): bool
    {
        return $byte < 32 || 127 === $byte;
    }

    private static function isSpace(int $byte): bool
    {
        return \in_array($byte, [9, 10, 11, 12, 13, 32], true);
    }

    private static function isPrint(int $byte): bool
    {
        return $byte >= 32 && $byte <= 126;
    }

    private static function isGraph(int $byte): bool
    {
        return self::isPrint($byte) && !self::isSpace($byte);
    }

    private static function isPunct(int $byte): bool
    {
        return self::isPrint($byte) && !self::isAlnum($byte) && !self::isSpace($byte);
    }
}
