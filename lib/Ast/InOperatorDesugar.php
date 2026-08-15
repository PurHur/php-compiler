<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\ReferenceProfileTokenScan;

/**
 * php-src has no `$needle in $haystack` operator (Zend/zend_language_parser.y).
 *
 * Previously desugared to `__phpcLangIn()` as a PHP 8.3+ experiment (#4682). php-src-strict
 * rejects the token instead (#31158). {@see desugar()} is a no-op so the rewrite cannot
 * turn invalid PHP into valid PHP.
 *
 * Rejector lives on this existing inventory unit (not a new `lib/` file) so the M2 spine
 * sidecar hash stays honest.
 */
final class InOperatorDesugar
{
    /** Zend 8.2–8.5 message for `echo 1 in [1,2];` (zend_language_parser.y). */
    public const PARSE_ERROR_UNEXPECTED_IN = 'syntax error, unexpected identifier "in", expecting "," or ";"';

    /**
     * Reject infix `in` before any rewrite (#31158). Same role as PipeOperatorSyntaxRejector.
     */
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        $error = self::syntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }

    /**
     * No-op: do not rewrite `in` into a call (#31158).
     */
    public static function desugar(string $code): string
    {
        return $code;
    }

    /**
     * @return array{line: int, message: string}|null
     */
    public static function syntaxError(string $code): ?array
    {
        if (!preg_match('/(?<![\w\$])in(?![\w\$])/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        for ($i = 0, $c = count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            if (0 !== strcasecmp($token[1], 'in')) {
                continue;
            }
            if (!self::isInfixOperatorIn($tokens, $i)) {
                continue;
            }

            return [
                'line' => max(1, (int) $token[2]),
                'message' => sprintf(
                    'syntax error, unexpected identifier "%s", expecting "," or ";"',
                    $token[1]
                ),
            ];
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isInfixOperatorIn(array $tokens, int $inIdx): bool
    {
        $prev = $inIdx - 1;
        self::skipBackwardIgnorable($tokens, $prev);
        if ($prev < 0 || !self::isExprEnder($tokens[$prev])) {
            return false;
        }

        $next = $inIdx + 1;
        self::skipForwardIgnorable($tokens, $next);
        if ($next >= count($tokens) || !self::isExprStarter($tokens[$next])) {
            return false;
        }

        return true;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isExprEnder($token): bool
    {
        if (\is_string($token)) {
            return \in_array($token, [')', ']', '}'], true);
        }

        return \in_array($token[0], [
            T_VARIABLE, T_STRING, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING,
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
            T_DIR, T_FILE, T_LINE, T_NS_C, T_CLASS_C, T_TRAIT_C, T_FUNC_C, T_METHOD_C,
            T_NUM_STRING,
        ], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isExprStarter($token): bool
    {
        if (\is_string($token)) {
            return \in_array($token, ['(', '['], true);
        }

        return \in_array($token[0], [
            T_VARIABLE, T_STRING, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING,
            T_ARRAY, T_NEW, T_CLONE, T_LIST, T_FN, T_FUNCTION, T_MATCH,
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
            T_DIR, T_FILE, T_LINE, T_NS_C, T_CLASS_C, T_TRAIT_C, T_FUNC_C, T_METHOD_C,
            T_NS_SEPARATOR,
        ], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isIgnorable($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBackwardIgnorable(array $tokens, int &$pos): void
    {
        while ($pos >= 0 && self::isIgnorable($tokens[$pos])) {
            --$pos;
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipForwardIgnorable(array $tokens, int &$pos): void
    {
        while ($pos < count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
    }
}
