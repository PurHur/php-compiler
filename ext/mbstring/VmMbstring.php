<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Shared mbstring VM helpers (php-src ext/mbstring/mbstring.c; #7014, #3239).
 */
final class VmMbstring
{
    public static function coerceModeArg(Variable $var, string $function, int $argIndex = 1): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return self::validateMode($var->toInt(), $function, $argIndex);
    }

    public static function coerceEncodingArg(
        Variable $var,
        string $function,
        int $argIndex = 2,
        string $default = 'UTF-8'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return $default;
        }

        return self::coerceEncodingString($var, $function, $argIndex);
    }

    public static function coerceEncodingString(Variable $var, string $function, int $argIndex = 2): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function validateMode(int $mode, string $function, int $argIndex = 1): int
    {
        if ($mode < MbstringConstants::MB_CASE_UPPER || $mode > MbstringConstants::MB_CASE_TITLE) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($mode) must be one of the MB_CASE_* constants',
                $function,
                $argIndex + 1
            ));
        }

        return $mode;
    }

    public static function convertCase(string $source, int $mode, string $encoding = 'UTF-8'): string
    {
        if (\function_exists('mb_convert_case')) {
            return \mb_convert_case($source, $mode, $encoding);
        }

        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_convert_case() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }

        return match ($mode) {
            MbstringConstants::MB_CASE_UPPER => self::asciiUpper($source),
            MbstringConstants::MB_CASE_LOWER => self::asciiLower($source),
            MbstringConstants::MB_CASE_TITLE => self::asciiTitle($source),
            default => throw new \ValueError('mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants'),
        };
    }

    private static function asciiUpper(string $source): string
    {
        return strtr($source, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    private static function asciiLower(string $source): string
    {
        return strtr($source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    private static function asciiTitle(string $source): string
    {
        return ucwords(self::asciiLower($source));
    }

    public static function coerceOffsetArg(Variable $var, string $function, int $argIndex = 2): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($offset) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($offset) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    public static function coercePartArg(Variable $var, string $function, int $argIndex = 2): bool
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($before_needle) must be of type bool, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($before_needle) must be of type bool, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toBool();
    }

    /**
     * @return int|false
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        if (\function_exists('mb_stripos')) {
            return \mb_stripos($haystack, $needle, $offset, $encoding);
        }

        return self::utf8Strpos($haystack, $needle, $offset, true, $encoding, 'mb_stripos');
    }

    /**
     * @return int|false
     */
    public static function strrpos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        if (\function_exists('mb_strrpos')) {
            return \mb_strrpos($haystack, $needle, $offset, $encoding);
        }

        return self::utf8Strrpos($haystack, $needle, $offset, false, $encoding, 'mb_strrpos');
    }

    /**
     * @return string|false
     */
    public static function strrichr(string $haystack, string $needle, bool $part = false, string $encoding = 'UTF-8')
    {
        if (\function_exists('mb_strrichr')) {
            return \mb_strrichr($haystack, $needle, $part, $encoding);
        }

        self::assertSearchEncoding($encoding);
        $lowerHay = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
        $lowerNeedle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        $pos = self::utf8Strrpos($lowerHay, $lowerNeedle, 0, false, $encoding, 'mb_strrichr');
        if (false === $pos) {
            return false;
        }
        if ($part) {
            return VmString::utf8CharSubstr($haystack, 0, $pos);
        }

        return VmString::utf8CharSubstr(
            $haystack,
            $pos,
            VmString::utf8CharLength($haystack) - $pos
        );
    }

    /**
     * @return int|false
     */
    private static function utf8Strpos(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive,
        string $encoding,
        string $function
    ) {
        self::assertSearchEncoding($encoding);
        if ($caseInsensitive) {
            $haystack = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
            $needle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        }
        $hayLen = VmString::utf8CharLength($haystack);
        $needleLen = VmString::utf8CharLength($needle);
        $offset = self::normalizeCharOffset($hayLen, $offset, $function);
        if (0 === $needleLen) {
            return $offset;
        }
        for ($pos = $offset; $pos <= $hayLen - $needleLen; ++$pos) {
            if (VmString::utf8CharSubstr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
        }

        return false;
    }

    /**
     * @return int|false
     */
    private static function utf8Strrpos(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive,
        string $encoding,
        string $function
    ) {
        self::assertSearchEncoding($encoding);
        if ($caseInsensitive) {
            $haystack = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
            $needle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        }
        $hayLen = VmString::utf8CharLength($haystack);
        $needleLen = VmString::utf8CharLength($needle);
        $minStart = 0;
        $maxStart = $hayLen - $needleLen;
        if ($offset < 0) {
            $maxStart = $hayLen + $offset;
            if ($maxStart < 0) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #3 ($offset) must be contained in argument #1 ($haystack)',
                    $function
                ));
            }
            if (0 === $needleLen) {
                return $maxStart;
            }
            $maxStart -= $needleLen;
        } else {
            $minStart = $offset;
        }
        if (0 === $needleLen) {
            return $hayLen;
        }
        if ($minStart > $maxStart) {
            return false;
        }
        for ($pos = $maxStart; $pos >= $minStart; --$pos) {
            if (VmString::utf8CharSubstr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
        }

        return false;
    }

    private static function normalizeCharOffset(int $hayLen, int $offset, string $function): int
    {
        if ($offset < 0) {
            $offset += $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            throw new \ValueError(sprintf(
                '%s(): Argument #3 ($offset) must be contained in argument #1 ($haystack)',
                $function
            ));
        }

        return $offset;
    }

    private static function assertSearchEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mbstring search requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}
