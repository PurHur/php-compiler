<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;

/**
 * mbstring encoding name registry (php-src ext/mbstring/mbfl_encoding; #13100).
 */
final class MbstringEncodingRegistry
{
    /** @var array<string, array{canonical: string, mime: string, aliases: list<string>}> */
    private const ENCODINGS = [
        'UTF-8' => [
            'canonical' => 'UTF-8',
            'mime' => 'UTF-8',
            'aliases' => ['utf8'],
        ],
        'ASCII' => [
            'canonical' => 'ASCII',
            'mime' => 'US-ASCII',
            'aliases' => [
                'ANSI_X3.4-1968', 'iso-ir-6', 'ANSI_X3.4-1986', 'ISO_646.irv:1991',
                'US-ASCII', 'ISO646-US', 'us', 'IBM367', 'IBM-367', 'cp367', 'csASCII',
            ],
        ],
        'ISO-8859-1' => [
            'canonical' => 'ISO-8859-1',
            'mime' => 'ISO-8859-1',
            'aliases' => ['latin1', 'LATIN1', 'ISO8859-1', 'ISO88591'],
        ],
        'SJIS' => [
            'canonical' => 'SJIS',
            'mime' => 'Shift_JIS',
            'aliases' => ['x-sjis', 'SHIFT-JIS'],
        ],
        'EUC-JP' => [
            'canonical' => 'EUC-JP',
            'mime' => 'EUC-JP',
            'aliases' => ['EUC', 'EUC_JP', 'eucJP', 'x-euc-jp'],
        ],
        'ISO-2022-JP' => [
            'canonical' => 'ISO-2022-JP',
            'mime' => 'ISO-2022-JP',
            'aliases' => [],
        ],
        'CP932' => [
            'canonical' => 'CP932',
            'mime' => 'Shift_JIS',
            'aliases' => ['MS932', 'Windows-31J', 'MS_Kanji'],
        ],
        '8BIT' => [
            'canonical' => '8BIT',
            'mime' => '8bit',
            'aliases' => ['binary'],
        ],
    ];

    public static function resolve(string $name): ?string
    {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            return null;
        }

        $canonical = CharsetEngine::canonicalize($trimmed);
        if (null !== $canonical && isset(self::ENCODINGS[$canonical])) {
            return $canonical;
        }

        $upper = strtoupper(str_replace(['-', '_', ' '], '', $trimmed));
        foreach (self::ENCODINGS as $key => $meta) {
            if ($upper === strtoupper(str_replace(['-', '_', ' '], '', $key))) {
                return $key;
            }
            foreach ($meta['aliases'] as $alias) {
                if ($upper === strtoupper(str_replace(['-', '_', ' ', ':'], '', $alias))) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function isValid(string $name): bool
    {
        return null !== self::resolve($name);
    }

    public static function assertValid(string $name, string $function, int $argIndex): string
    {
        $canonical = self::resolve($name);
        if (null === $canonical) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($encoding) must be a valid encoding, "%s" given',
                $function,
                $argIndex + 1,
                $name
            ));
        }

        return $canonical;
    }

    public static function preferredMimeName(string $canonical): string|false
    {
        $meta = self::ENCODINGS[$canonical] ?? null;
        if (null === $meta || '' === $meta['mime']) {
            return false;
        }

        return $meta['mime'];
    }

    /**
     * @return list<string>
     */
    public static function aliases(string $canonical): array
    {
        return self::ENCODINGS[$canonical]['aliases'] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function parseOrderList(string $function, int $argIndex, string $list): array
    {
        $parts = preg_split('/\s*,\s*/', $list) ?: [];
        $order = [];
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }
            $canonical = self::resolve($part);
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #%d ($encoding) contains invalid encoding "%s"',
                    $function,
                    $argIndex + 1,
                    $part
                ));
            }
            $order[] = $canonical;
        }
        self::assertNonEmptyOrder($function, $argIndex, $order);

        return $order;
    }

    /**
     * @param list<string> $order
     */
    public static function assertNonEmptyOrder(string $function, int $argIndex, array $order): void
    {
        if ([] === $order) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($encoding) must specify at least one encoding',
                $function,
                $argIndex + 1
            ));
        }
    }
}
