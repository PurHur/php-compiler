<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject bare `throw;` (catch rethrow) on the Zend 8.2 reference profile (#14239).
 *
 * php-src: Zend/zend_language_parser.y — bare rethrow is not valid on PHP 8.2.
 * PHP 8.4+ allows bare rethrow — gated by {@see CompilerVersion::supportsBareRethrow()} (#3508).
 */
final class BareThrowSyntaxRejector
{
    private const MESSAGE = 'syntax error, unexpected token ";"';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsBareRethrow()) {
            return $code;
        }
        if (!str_contains($code, 'throw')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || T_THROW !== $tok[0]) {
                continue;
            }

            $j = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($j >= $n) {
                continue;
            }

            $next = $tokens[$j];
            if (!\is_array($next) && ';' === $next) {
                $line = $tok[2] ?? 1;
                throw new CompileFatal($filename, (int) $line, self::MESSAGE);
            }
        }

        return $code;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function nextMeaningfulTokenIndex(array $tokens, int $start): int
    {
        $n = \count($tokens);
        for ($j = $start; $j < $n; ++$j) {
            $tok = $tokens[$j];
            if (!\is_array($tok)) {
                return $j;
            }
            $id = $tok[0];
            if (\in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG_WITH_ECHO], true)) {
                continue;
            }

            return $j;
        }

        return $n;
    }
}
