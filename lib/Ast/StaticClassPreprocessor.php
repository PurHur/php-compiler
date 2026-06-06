<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Strip `static class` for nikic/php-parser (no class-level T_STATIC before T_CLASS) (#6929).
 *
 * php-src: Zend/zend_language_parser.y — static class modifier (PHP 8.4 RFC).
 */
final class StaticClassPreprocessor
{
    /** @var array<int, true> declaration start line => static class */
    private array $staticLines = [];

    /**
     * @return array{0: string, 1: array<int, true>}
     */
    public function preprocess(string $code): array
    {
        $this->staticLines = [];
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
     * @return array<int, true>
     */
    public function staticLines(): array
    {
        return $this->staticLines;
    }
}
