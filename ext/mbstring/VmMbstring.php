<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
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
        return self::utf8Strpos($haystack, $needle, $offset, true, $encoding, 'mb_stripos');
    }

    /**
     * @return int|false
     */
    public static function strrpos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strrpos($haystack, $needle, $offset, false, $encoding, 'mb_strrpos');
    }

    /**
     * @return string|false
     */
    public static function strrichr(string $haystack, string $needle, bool $part = false, string $encoding = 'UTF-8')
    {
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

    /**
     * @return array<int, mixed>|string|int|null
     */
    public static function coerceCheckEncodingValueArg(
        Variable $var,
        string $function,
        int $argIndex = 0
    ): array|string|int|null {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $out = [];
            foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
                $elem = $elem->resolveIndirect();
                if (Variable::TYPE_OBJECT === $elem->type) {
                    throw new \LogicException(
                        $function.'(): array value contains object; use checkEncodingForVariable()'
                    );
                }
                $out[] = self::checkEncodingScalarToPhp($elem);
            }

            return $out;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                $var->toObject()->class->name
            ));
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
            $function,
            $argIndex + 1,
            self::typeLabel($var)
        ));
    }

    public static function checkEncodingForVariable(?Variable $valueVar, ?string $encoding = null): bool
    {
        if (null === $valueVar) {
            return self::checkEncoding(null, $encoding);
        }
        $var = $valueVar->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
                if (Variable::TYPE_OBJECT === $elem->resolveIndirect()->type) {
                    return false;
                }
            }
        }

        return self::checkEncoding(
            self::coerceCheckEncodingValueArg($valueVar, 'mb_check_encoding', 0),
            $encoding
        );
    }

    /**
     * @param array<int, mixed>|string|int|null $value
     */
    public static function checkEncoding(array|string|int|null $value = null, ?string $encoding = null): bool
    {
        $encoding = null === $encoding ? 'UTF-8' : $encoding;
        self::assertCheckEncodingName($encoding);

        if (null === $value) {
            return true;
        }
        if (\is_int($value)) {
            $value = (string) $value;
        }
        if (\is_string($value)) {
            return self::isValidInEncoding($value, $encoding);
        }

        foreach ($value as $item) {
            if (\is_object($item)) {
                return false;
            }
            if (\is_int($item)) {
                $item = (string) $item;
            }
            if (!\is_string($item) || !self::isValidInEncoding($item, $encoding)) {
                return false;
            }
        }

        return true;
    }

    public static function assertCheckEncodingName(string $encoding): void
    {
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            throw new \ValueError(sprintf(
                'mb_check_encoding(): Argument #2 ($encoding) must be a valid encoding, "%s" given',
                $encoding
            ));
        }
    }

    private static function isValidInEncoding(string $value, string $encoding): bool
    {
        $canonical = CharsetEngine::canonicalize($encoding) ?? $encoding;
        if ('UTF-8' === $canonical) {
            return self::isValidUtf8($value);
        }
        if ('ASCII' === $canonical || '8BIT' === $canonical) {
            return true;
        }

        throw new \LogicException(
            'mb_check_encoding() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }

    private static function isValidUtf8(string $value): bool
    {
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($value[$i]);
            if ($byte < 0x80) {
                ++$i;
                continue;
            }
            if (($byte & 0xE0) === 0xC0) {
                $need = 1;
                $min = 0x80;
            } elseif (($byte & 0xF0) === 0xE0) {
                $need = 2;
                $min = 0x800;
            } elseif (($byte & 0xF8) === 0xF0) {
                $need = 3;
                $min = 0x10000;
            } else {
                return false;
            }
            if ($i + $need >= $len) {
                return false;
            }
            $cp = $byte & (0xFF >> (2 + $need));
            for ($j = 1; $j <= $need; ++$j) {
                $next = \ord($value[$i + $j]);
                if (($next & 0xC0) !== 0x80) {
                    return false;
                }
                $cp = ($cp << 6) | ($next & 0x3F);
            }
            if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    /**
     * @return string|int|float|bool|null
     */
    private static function checkEncodingScalarToPhp(Variable $var): string|int|float|bool|null
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => null,
        };
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
