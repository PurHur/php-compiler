<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Desugar PHP 8.4+ parenthesized exit/die to callable builtins before nikic/php-parser (#6975).
 *
 * Rewrites `exit(` / `die(` (including FCC, named args, two-arg) to `exit_(` / `die_(` identifiers.
 * Bare `exit;` / `exit 1;` stay language constructs (CFG Exit_).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(exit)
 */
final class ExitFunctionDesugar
{
    public const MARKER_EXIT = '__phpcExitCall';

    public const MARKER_DIE = '__phpcDieCall';

    /** Zend 8.2 profile message for exit(status:)/die(message:) (#13973). */
    public const REFERENCE_PROFILE_UNEXPECTED_COLON = 'syntax error, unexpected token ":"';

    /** Zend 8.2 profile message for exit($a, $b) two-arg form (#13973). */
    public const REFERENCE_PROFILE_UNEXPECTED_COMMA = 'syntax error, unexpected token ","';

    /** Zend 8.2 profile message for exit(...)/die(...) FCC (#13973). */
    public const REFERENCE_PROFILE_UNEXPECTED_ELLIPSIS = 'syntax error, unexpected token "..."';

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (!preg_match('/\b(?:exit|die)\s*\(/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (!self::isExitKeyword($tokens, $i) || !self::hasParenCall($tokens, $i)) {
                continue;
            }
            $violation = self::parenCallReferenceProfileViolation($tokens, $i);
            if (null === $violation) {
                continue;
            }
            $start = self::tokenByteOffset($tokens, $i);
            if (null === $start) {
                continue;
            }

            return [
                'line' => self::byteOffsetToLine($code, $start),
                'message' => $violation,
            ];
        }

        return null;
    }

    public static function desugar(string $code): string
    {
        if (!CompilerVersion::supportsExitFunctionForm()) {
            return $code;
        }
        if (!preg_match('/\b(?:exit|die)\s*\(/i', $code)) {
            return $code;
        }

        $tokens = token_get_all($code);
        $replacements = [];
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (!self::isExitKeyword($tokens, $i) || !self::hasParenCall($tokens, $i)) {
                continue;
            }
            $keyword = strtolower(self::tokenText($tokens[$i]));
            $replacement = 'die' === $keyword ? self::MARKER_DIE : self::MARKER_EXIT;
            $start = self::tokenByteOffset($tokens, $i);
            if (null === $start) {
                continue;
            }
            $end = $start + \strlen(self::tokenText($tokens[$i]));
            $replacements[] = ['start' => $start, 'end' => $end, 'text' => $replacement];
        }

        if ([] === $replacements) {
            return $code;
        }

        usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($replacements as $r) {
            $code = substr($code, 0, $r['start']).$r['text'].substr($code, $r['end']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isExitKeyword(array $tokens, int $i): bool
    {
        if (!isset($tokens[$i]) || !\is_array($tokens[$i])) {
            return false;
        }

        return \T_EXIT === $tokens[$i][0];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function hasParenCall(array $tokens, int $exitIdx): bool
    {
        $pos = $exitIdx + 1;
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }

        return $pos < \count($tokens) && \is_string($tokens[$pos]) && '(' === $tokens[$pos];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isIgnorable($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_OPEN_TAG_WITH_ECHO], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenByteOffset(array $tokens, int $idx): ?int
    {
        if (!isset($tokens[$idx])) {
            return null;
        }
        $offset = 0;
        for ($i = 0; $i < $idx; ++$i) {
            $offset += \strlen(self::tokenText($tokens[$i]));
        }

        return $offset;
    }

    /**
     * PHP 8.4-only parenthesized exit/die forms: named args, two-arg, FCC (#13973).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function parenCallReferenceProfileViolation(array $tokens, int $exitIdx): ?string
    {
        $openIdx = $exitIdx + 1;
        while ($openIdx < \count($tokens) && self::isIgnorable($tokens[$openIdx])) {
            ++$openIdx;
        }
        if ($openIdx >= \count($tokens) || !\is_string($tokens[$openIdx]) || '(' !== $tokens[$openIdx]) {
            return null;
        }

        $depth = 0;
        $prevAtArgLevel = null;
        for ($i = $openIdx; $i < \count($tokens); ++$i) {
            $token = $tokens[$i];
            if (self::isIgnorable($token)) {
                continue;
            }
            if (\is_string($token)) {
                if ('(' === $token) {
                    ++$depth;
                    if (1 === $depth) {
                        $prevAtArgLevel = null;
                    }
                    continue;
                }
                if (')' === $token) {
                    if (0 === $depth) {
                        return null;
                    }
                    --$depth;
                    if (0 === $depth) {
                        break;
                    }
                    continue;
                }
                if (1 === $depth) {
                    if (',' === $token) {
                        return self::REFERENCE_PROFILE_UNEXPECTED_COMMA;
                    }
                    if (':' === $token
                        && \is_array($prevAtArgLevel)
                        && \T_STRING === $prevAtArgLevel[0]) {
                        return self::REFERENCE_PROFILE_UNEXPECTED_COLON;
                    }
                    $prevAtArgLevel = $token;
                }
                continue;
            }

            if (1 === $depth) {
                if (\T_ELLIPSIS === $token[0]) {
                    return self::REFERENCE_PROFILE_UNEXPECTED_ELLIPSIS;
                }
                $prevAtArgLevel = $token;
            }
        }

        return null;
    }

    private static function byteOffsetToLine(string $code, int $offset): int
    {
        return 1 + substr_count(substr($code, 0, max(0, $offset)), "\n");
    }
}
