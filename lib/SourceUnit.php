<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * One tokenization + newline index per source snapshot during preprocess (#36228).
 *
 * Rejectors and desugar passes on the same $code string share a single token_get_all()
 * result; byte-offset line lookup uses a prefix array instead of substr_count scans.
 */
final class SourceUnit
{
    private static ?string $code = null;

    /** @var list<array{0: int, 1: string, 2: int}|string>|null */
    private static ?array $tokens = null;

    /** @var list<int>|null byte offset of each line start (index 0 = line 1) */
    private static ?array $lineStarts = null;

    public static function begin(string $code): void
    {
        self::bind($code);
    }

    public static function end(): void
    {
        self::$code = null;
        self::$tokens = null;
        self::$lineStarts = null;
    }

    /**
     * Rebind after a rewriter produced new source (invalidates token/line caches).
     */
    public static function bind(string $code): void
    {
        if (self::$code === $code) {
            return;
        }
        self::$code = $code;
        self::$tokens = null;
        self::$lineStarts = null;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    public static function tokens(string $code): array
    {
        self::bind($code);
        if (null === self::$tokens) {
            self::$tokens = \function_exists('token_get_all') ? token_get_all($code) : [];
        }

        return self::$tokens;
    }

    public static function byteOffsetToLine(string $code, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }
        self::ensureLineStarts($code);
        $starts = self::$lineStarts;
        if (null === $starts || [] === $starts) {
            return 1;
        }
        $lo = 0;
        $hi = \count($starts) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($starts[$mid] <= $offset) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo + 1;
    }

    private static function ensureLineStarts(string $code): void
    {
        self::bind($code);
        if (null !== self::$lineStarts) {
            return;
        }
        $starts = [0];
        $len = \strlen($code);
        for ($i = 0; $i < $len; ++$i) {
            if ("\n" === $code[$i]) {
                $starts[] = $i + 1;
            }
        }
        self::$lineStarts = $starts;
    }
}
