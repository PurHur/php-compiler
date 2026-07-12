<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ext\standard\VmString;

/**
 * Character-indexed string ops in a single encoding (php-src ext/iconv/iconv.c; #6247).
 */
final class CharsetString
{
    /**
     * @return list<string>|null null when input is invalid for the encoding
     */
    public static function splitCharacters(string $encoding, string $input): ?array
    {
        $parsed = CharsetEngine::parseEncodingSpec($encoding);
        if (null === $parsed) {
            return null;
        }
        [$canon] = $parsed;

        return match ($canon) {
            'UTF-8' => self::splitUtf8Characters($input),
            'ISO-8859-1', 'ASCII' => self::splitSingleByteCharacters($input),
            'UTF-16LE' => self::splitUtf16Characters($input, false),
            'UTF-16BE' => self::splitUtf16Characters($input, true),
            default => null,
        };
    }

    /**
     * @return list<string>|null
     */
    private static function splitUtf8Characters(string $input): ?array
    {
        if (!VmString::isValidUtf8($input)) {
            return null;
        }
        $chars = [];
        $len = \strlen($input);
        for ($i = 0; $i < $len; ) {
            $width = VmString::utf8CharByteWidth($input, $i);
            $chars[] = \substr($input, $i, $width);
            $i += $width;
        }

        return $chars;
    }

    /** @return list<string> */
    private static function splitSingleByteCharacters(string $input): array
    {
        $chars = [];
        $len = \strlen($input);
        for ($i = 0; $i < $len; ++$i) {
            $chars[] = $input[$i];
        }

        return $chars;
    }

    /**
     * @return list<string>|null
     */
    private static function splitUtf16Characters(string $input, bool $be): ?array
    {
        $len = \strlen($input);
        if (0 !== $len % 2) {
            return null;
        }
        $chars = [];
        for ($i = 0; $i < $len; $i += 2) {
            $chars[] = \substr($input, $i, 2);
        }

        return $chars;
    }

    public static function strlen(string $encoding, string $input): int|false
    {
        $chars = self::splitCharacters($encoding, $input);
        if (null === $chars) {
            return false;
        }

        return \count($chars);
    }

    public static function substr(string $encoding, string $input, int $offset, ?int $length): string|false
    {
        $chars = self::splitCharacters($encoding, $input);
        if (null === $chars) {
            return false;
        }
        $count = \count($chars);
        if ($offset < 0) {
            $offset += $count;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if ($offset >= $count) {
            return '';
        }
        if (null === $length) {
            $slice = \array_slice($chars, $offset);
        } else {
            if ($length < 0) {
                $length = $count - $offset + $length;
                if ($length < 0) {
                    return '';
                }
            }
            $slice = \array_slice($chars, $offset, $length);
        }

        return \implode('', $slice);
    }

    public static function strpos(string $encoding, string $haystack, string $needle, int $offset): int|false
    {
        if ('' === $needle) {
            return false;
        }
        $hayChars = self::splitCharacters($encoding, $haystack);
        if (null === $hayChars) {
            return false;
        }
        $needleChars = self::splitCharacters($encoding, $needle);
        if (null === $needleChars || [] === $needleChars) {
            return false;
        }
        $hayCount = \count($hayChars);
        if ($offset < 0) {
            $offset += $hayCount;
            if ($offset < 0) {
                throw new \ValueError('iconv_strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
            }
        }
        if ($offset > $hayCount) {
            throw new \ValueError('iconv_strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
        }
        $needleCount = \count($needleChars);
        $limit = $hayCount - $needleCount;
        for ($i = $offset; $i <= $limit; ++$i) {
            $match = true;
            for ($j = 0; $j < $needleCount; ++$j) {
                if ($hayChars[$i + $j] !== $needleChars[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $i;
            }
        }

        return false;
    }

    public static function strrpos(string $encoding, string $haystack, string $needle): int|false
    {
        if ('' === $needle) {
            return false;
        }
        $hayChars = self::splitCharacters($encoding, $haystack);
        if (null === $hayChars) {
            return false;
        }
        $needleChars = self::splitCharacters($encoding, $needle);
        if (null === $needleChars || [] === $needleChars) {
            return false;
        }
        $hayCount = \count($hayChars);
        $needleCount = \count($needleChars);
        $limit = $hayCount - $needleCount;
        for ($i = $limit; $i >= 0; --$i) {
            $match = true;
            for ($j = 0; $j < $needleCount; ++$j) {
                if ($hayChars[$i + $j] !== $needleChars[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $i;
            }
        }

        return false;
    }
}
