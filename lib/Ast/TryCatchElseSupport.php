<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Detect try/catch/else for php-src-strict Parse error (#31159).
 *
 * Extraction of else arms is gated on {@see CompilerVersion::supportsTryCatchElse()}, which
 * is always false on php-src-strict (php-src never shipped this production).
 * php-src: Zend/zend_language_parser.y try_catch_list; zend_compile.c zend_compile_try.
 */
final class TryCatchElseSupport
{
    public const ATTRIBUTE = 'compilerTryCatchElseSource';

    /** Zend Parse error message for try/catch/else (#31159, Zend/zend_language_parser.y). */
    public const REFERENCE_PROFILE_UNEXPECTED_ELSE = 'syntax error, unexpected token "else"';

    /** @var list<string> else-block inner sources (statements only), FIFO per compilation unit */
    private static array $pendingElseSources = [];

    public static function beginCompilationUnit(): void
    {
        self::$pendingElseSources = [];
    }

    /**
     * @return list<string>
     */
    public static function pendingElseSources(): array
    {
        return self::$pendingElseSources;
    }

    public static function takeNextElseSource(): ?string
    {
        if ([] === self::$pendingElseSources) {
            return null;
        }

        return array_shift(self::$pendingElseSources);
    }

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (CompilerVersion::supportsTryCatchElse()) {
            return null;
        }
        if (!preg_match('/\btry\b/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (!self::isTryKeyword($tokens, $i)) {
                continue;
            }
            $elseIdx = self::findTryCatchElseKeywordIndex($tokens, $i);
            if (null === $elseIdx) {
                continue;
            }
            $start = self::tokenByteOffset($tokens, $elseIdx);
            if (null === $start) {
                continue;
            }

            return [
                'line' => self::byteOffsetToLine($code, $start),
                'message' => self::REFERENCE_PROFILE_UNEXPECTED_ELSE,
            ];
        }

        return null;
    }

    /**
     * Strip try/catch/else clauses so php-parser accepts the unit; queue else bodies.
     */
    public static function extract(string $code): string
    {
        if (!CompilerVersion::supportsTryCatchElse()) {
            return $code;
        }
        if (!preg_match('/\btry\b/i', $code)) {
            return $code;
        }

        for ($guard = 0; $guard < 256; ++$guard) {
            $tokens = token_get_all($code);
            $span = self::findNextTryCatchElseSpan($code, $tokens);
            if (null === $span) {
                break;
            }
            self::$pendingElseSources[] = $span['elseInner'];
            $code = substr($code, 0, $span['elseStart'])
                .substr($code, $span['elseEnd']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findNextTryCatchElseSpan(string $code, array $tokens): ?array
    {
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (!self::isTryKeyword($tokens, $i)) {
                continue;
            }
            $elseIdx = self::findTryCatchElseKeywordIndex($tokens, $i);
            if (null === $elseIdx) {
                continue;
            }
            $braceOpen = self::skipWhitespace($tokens, $elseIdx + 1, $c);
            if (null === $braceOpen || '{' !== self::tokenText($tokens[$braceOpen])) {
                continue;
            }
            $braceSpan = self::matchingBraceSpan($tokens, $braceOpen);
            if (null === $braceSpan) {
                continue;
            }
            [$bodyOpen, $bodyClose] = $braceSpan;
            $elseStart = self::tokenByteOffset($tokens, $elseIdx);
            $elseEnd = self::tokenSpanEnd($tokens, $bodyClose);
            if (null === $elseStart || null === $elseEnd) {
                continue;
            }
            $innerStart = self::tokenSpanEnd($tokens, $bodyOpen);
            if (null === $innerStart) {
                continue;
            }
            $innerEnd = self::tokenByteOffset($tokens, $bodyClose);
            if (null === $innerEnd) {
                continue;
            }

            return [
                'elseStart' => $elseStart,
                'elseEnd' => $elseEnd,
                'elseInner' => substr($code, $innerStart, $innerEnd - $innerStart),
            ];
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findTryCatchElseKeywordIndex(array $tokens, int $tryIdx): ?int
    {
        $i = $tryIdx + 1;
        $c = \count($tokens);
        $i = self::skipWhitespace($tokens, $i, $c);
        if (null === $i || '{' !== self::tokenText($tokens[$i])) {
            return null;
        }
        $tryBrace = self::matchingBraceSpan($tokens, $i);
        if (null === $tryBrace) {
            return null;
        }
        $i = $tryBrace[1] + 1;
        $sawCatch = false;
        while ($i < $c) {
            $i = self::skipWhitespace($tokens, $i, $c);
            if (null === $i) {
                return null;
            }
            if (self::isCatchKeyword($tokens, $i)) {
                $sawCatch = true;
                $i = self::skipCatchClause($tokens, $i, $c);
                if (null === $i) {
                    return null;
                }
                continue;
            }
            if ($sawCatch && self::isElseKeyword($tokens, $i)) {
                return $i;
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipCatchClause(array $tokens, int $catchIdx, int $c): ?int
    {
        $i = $catchIdx + 1;
        $i = self::skipWhitespace($tokens, $i, $c);
        if (null === $i || '(' !== self::tokenText($tokens[$i])) {
            return null;
        }
        $depth = 0;
        for (; $i < $c; ++$i) {
            $text = self::tokenText($tokens[$i]);
            if ('(' === $text) {
                ++$depth;
            } elseif (')' === $text) {
                --$depth;
                if (0 === $depth) {
                    ++$i;
                    break;
                }
            }
        }
        $i = self::skipWhitespace($tokens, $i, $c);
        if (null === $i || '{' !== self::tokenText($tokens[$i])) {
            return null;
        }
        $brace = self::matchingBraceSpan($tokens, $i);
        if (null === $brace) {
            return null;
        }

        return $brace[1] + 1;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: int}|null [openIdx, closeIdx]
     */
    private static function matchingBraceSpan(array $tokens, int $openIdx): ?array
    {
        if ('{' !== self::tokenText($tokens[$openIdx])) {
            return null;
        }
        $depth = 0;
        $c = \count($tokens);
        for ($i = $openIdx; $i < $c; ++$i) {
            $text = self::tokenText($tokens[$i]);
            if ('{' === $text) {
                ++$depth;
            } elseif ('}' === $text) {
                --$depth;
                if (0 === $depth) {
                    return [$openIdx, $i];
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isTryKeyword(array $tokens, int $i): bool
    {
        return \is_array($tokens[$i]) && \T_TRY === $tokens[$i][0];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isCatchKeyword(array $tokens, int $i): bool
    {
        return \is_array($tokens[$i]) && \T_CATCH === $tokens[$i][0];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isElseKeyword(array $tokens, int $i): bool
    {
        return \is_array($tokens[$i]) && \T_ELSE === $tokens[$i][0];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipWhitespace(array $tokens, int $i, int $c): ?int
    {
        for (; $i < $c; ++$i) {
            if (!\is_array($tokens[$i])) {
                return $i;
            }
            if (!\in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenText(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenByteOffset(array $tokens, int $idx): ?int
    {
        if ($idx < 0 || $idx >= \count($tokens)) {
            return null;
        }
        $offset = 0;
        for ($i = 0; $i < $idx; ++$i) {
            $offset += \strlen(self::tokenText($tokens[$i]));
        }

        return $offset;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenSpanEnd(array $tokens, int $idx): ?int
    {
        $start = self::tokenByteOffset($tokens, $idx);
        if (null === $start) {
            return null;
        }

        return $start + \strlen(self::tokenText($tokens[$idx]));
    }

    private static function byteOffsetToLine(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, $offset), "\n") + 1;
    }
}
