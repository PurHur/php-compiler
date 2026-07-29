<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ReferenceProfileTokenScan;

/**
 * Strip `static class` for nikic/php-parser (no class-level T_STATIC before T_CLASS) (#6929).
 *
 * php-src: Zend/zend_language_parser.y — static class modifier (PHP 8.4 RFC).
 * Reference / 8.2 profile: reject with Zend 8.2 parse diagnostic (#24894).
 */
final class StaticClassPreprocessor
{
    /** Zend 8.2 / reference-profile diagnostic for `static class` (#24894). */
    public const PARSE_MESSAGE = 'syntax error, unexpected token "class", expecting "::"';

    /** @var array<int, true> declaration start line => static class */
    private array $staticLines = [];

    /**
     * @return array{0: string, 1: array<int, true>}
     */
    public function preprocess(string $code, string $filename = 'unknown'): array
    {
        $this->staticLines = [];
        if (!CompilerVersion::supportsStaticClass()) {
            if (!ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
                $error = self::referenceProfileSyntaxError($code);
                if (null !== $error) {
                    throw new CompileFatal($filename, $error['line'], $error['message']);
                }
            }

            return [$code, []];
        }

        $pattern = '/\bstatic\s+class\s+/';
        $offset = 0;
        $pieces = [];
        while (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchOffset = $m[0][1];
            $pieces[] = substr($code, $offset, $matchOffset - $offset);
            $line = substr_count(substr($code, 0, $matchOffset), "\n") + 1;
            $this->staticLines[$line] = true;
            $pieces[] = 'class ';
            $offset = $matchOffset + strlen($m[0][0]);
        }
        $out = [] === $pieces ? $code : implode('', $pieces).substr($code, $offset);

        return [$out, $this->staticLines];
    }

    /**
     * Locate `static class` for Zend 8.2-shaped reject (#24894).
     *
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $source): ?array
    {
        if (false === stripos($source, 'static') || false === stripos($source, 'class')) {
            return null;
        }
        if (!\function_exists('token_get_all')) {
            return null;
        }

        $tokens = token_get_all($source);
        $n = \count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || T_STATIC !== $tok[0]) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n) {
                $next = $tokens[$j];
                if (\is_array($next) && \in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    ++$j;
                    continue;
                }
                break;
            }
            if ($j < $n && \is_array($tokens[$j]) && T_CLASS === $tokens[$j][0]) {
                return [
                    'line' => (int) $tokens[$j][2],
                    'message' => self::PARSE_MESSAGE,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, true>
     */
    public function staticLines(): array
    {
        return $this->staticLines;
    }
}
