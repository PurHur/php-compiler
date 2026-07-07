<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Detect `new readonly class` — PHP 8.3+ (ZEND_ACC_READONLY_ANON_CLASS, #6991, #16255).
 *
 * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c — anonymous readonly class modifier.
 */
final class ReadonlyAnonymousClassSyntax
{
    /** Zend 8.2 reference profile message for `new readonly class` (#16255). */
    public const REFERENCE_PROFILE_UNEXPECTED_READONLY = 'syntax error, unexpected token "readonly"';

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        // JIT/AOT bundles can be megabytes with thousands of `new` — never token_get_all the whole
        // prelude (#17150). Require all three keywords before scanning.
        if (
            !preg_match('/\bnew\b/i', $code)
            || !preg_match('/\breadonly\b/i', $code)
            || !preg_match('/\bclass\b/i', $code)
        ) {
            return null;
        }

        return self::scanForNewReadonlyAnonymousClass($code);
    }

    /**
     * Bounded scan for `new readonly class` without token_get_all on huge sources (#17150).
     *
     * @return array{line: int, message: string}|null
     */
    private static function scanForNewReadonlyAnonymousClass(string $code): ?array
    {
        $offset = 0;
        $len = \strlen($code);
        while ($offset < $len) {
            if (!preg_match('/\bnew\b/i', $code, $match, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }
            $newOffset = (int) $match[0][1];
            $afterNew = self::skipInsignificantSource($code, $newOffset + \strlen((string) $match[0][0]));
            $readonlyOffset = self::matchReadonlyAt($code, $afterNew);
            if (null === $readonlyOffset) {
                $offset = $newOffset + 1;

                continue;
            }
            $afterReadonly = self::skipInsignificantSource($code, $readonlyOffset + 8);
            if (!self::matchClassAt($code, $afterReadonly)) {
                $offset = $newOffset + 1;

                continue;
            }

            return [
                'line' => self::lineAtOffset($code, $readonlyOffset),
                'message' => self::REFERENCE_PROFILE_UNEXPECTED_READONLY,
            ];
        }

        return null;
    }

    private static function skipInsignificantSource(string $code, int $offset): int
    {
        $len = \strlen($code);
        while ($offset < $len) {
            $ch = $code[$offset];
            if (' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch || "\f" === $ch) {
                ++$offset;

                continue;
            }
            if ('#' === $ch) {
                $offset = self::skipToLineEnd($code, $offset + 1);

                continue;
            }
            if ('/' === $ch && isset($code[$offset + 1])) {
                if ('/' === $code[$offset + 1]) {
                    $offset = self::skipToLineEnd($code, $offset + 2);

                    continue;
                }
                if ('*' === $code[$offset + 1]) {
                    $end = \strpos($code, '*/', $offset + 2);
                    $offset = false === $end ? $len : $end + 2;

                    continue;
                }
            }
            if ("'" === $ch || '"' === $ch) {
                $offset = self::skipQuotedString($code, $offset);

                continue;
            }
            if ('<' === $ch && str_starts_with($code, '<<', $offset)) {
                $offset = self::skipHeredoc($code, $offset);

                continue;
            }

            break;
        }

        return $offset;
    }

    private static function skipToLineEnd(string $code, int $offset): int
    {
        $len = \strlen($code);
        while ($offset < $len) {
            $ch = $code[$offset];
            if ("\n" === $ch || "\r" === $ch) {
                return $offset + 1;
            }
            ++$offset;
        }

        return $len;
    }

    private static function skipQuotedString(string $code, int $offset): int
    {
        $quote = $code[$offset];
        $len = \strlen($code);
        ++$offset;
        while ($offset < $len) {
            $ch = $code[$offset];
            if ('\\' === $ch) {
                $offset += 2;

                continue;
            }
            if ($quote === $ch) {
                return $offset + 1;
            }
            ++$offset;
        }

        return $len;
    }

    private static function skipHeredoc(string $code, int $offset): int
    {
        if (!preg_match('/\G<<<\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1/m', $code, $match, 0, $offset)) {
            return $offset + 1;
        }
        $label = $match[2];
        $bodyStart = $offset + \strlen($match[0]);
        $endMarker = "\n".$label;
        $end = \strpos($code, $endMarker, $bodyStart);
        if (false === $end) {
            return \strlen($code);
        }

        return $end + \strlen($endMarker);
    }

    private static function matchReadonlyAt(string $code, int $offset): ?int
    {
        if (!preg_match('/\Greadonly\b/i', $code, $match, 0, $offset)) {
            return null;
        }

        return $offset;
    }

    private static function matchClassAt(string $code, int $offset): bool
    {
        return 1 === preg_match('/\Gclass\b/i', $code, $m, 0, $offset);
    }

    private static function lineAtOffset(string $code, int $offset): int
    {
        return 1 + substr_count(substr($code, 0, $offset), "\n");
    }
}
