<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;


/**
 * parse_url() for compiled JIT/AOT modules (#9358, php-in-PHP).
 *
 * SSOT: {@see VmString::parseUrl()}
 * php-src: ext/standard/url.c — php_parse_url()
 */
final class ParseUrlJitHelper
{
    private const TAG_FALSE = 0;

    private const TAG_NULL = 1;

    private const TAG_STRING = 2;

    private const TAG_INT = 3;

    private static string $lastString = '';

    private static int $lastInt = 0;

    /** @return int TAG_* for LLVM bridge */
    public static function parseUrlComponent(string $url, int $component): int
    {
        $result = VmString::parseUrl($url, VmParseUrl::normalizeRawComponentInt($component));
        if (false === $result) {
            return self::TAG_FALSE;
        }
        if (null === $result) {
            return self::TAG_NULL;
        }
        if (\is_int($result)) {
            self::$lastInt = $result;

            return self::TAG_INT;
        }
        self::$lastString = (string) $result;

        return self::TAG_STRING;
    }

    public static function lastString(): string
    {
        return self::$lastString;
    }

    public static function lastInt(): int
    {
        return self::$lastInt;
    }

    /**
     * @return array<string, int|string>|null null when parse_url() returns false
     */
    public static function parseUrlAssoc(string $url): ?array
    {
        $result = VmString::parseUrl($url, -1);
        if (false === $result) {
            return null;
        }
        if (!\is_array($result)) {
            throw new \LogicException('parse_url() assoc mode must yield an array in this compiler build');
        }

        return $result;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastString = '';
        self::$lastInt = 0;
    }
}
