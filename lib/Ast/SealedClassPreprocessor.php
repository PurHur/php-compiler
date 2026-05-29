<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Strip sealed / permits syntax for nikic/php-parser (no T_SEALED) and record metadata (#3322).
 *
 * php-src: Zend/zend_language_parser.y, zend_compile.c — sealed modifier + permits clause.
 */
final class SealedClassPreprocessor
{
    /** @var array<int, list<string>> start line => permitted child FQCNs (lowercase) */
    private array $permitsByLine = [];

    /**
     * @return array{0: string, 1: array<int, list<string>>}
     */
    public function preprocess(string $code): array
    {
        $this->permitsByLine = [];
        $pattern = '/\bsealed\s+(class|interface)\s+((?:\\\\)?[A-Za-z_][\w\\\\]*)\s*(?:permits\s+((?:\\\\)?[A-Za-z_][\w\\\\]*(?:\s*,\s*(?:\\\\)?[A-Za-z_][\w\\\\]*)*))?\s*/';
        $offset = 0;
        $pieces = [];
        while (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchOffset = $m[0][1];
            $pieces[] = substr($code, $offset, $matchOffset - $offset);
            $line = substr_count(substr($code, 0, $matchOffset), "\n") + 1;
            $kind = $m[1][0];
            $name = $m[2][0];
            $permitsRaw = isset($m[3]) ? $m[3][0] : '';
            $permits = [];
            if ('' !== $permitsRaw) {
                foreach (preg_split('/\s*,\s*/', $permitsRaw) as $p) {
                    $p = trim($p);
                    if ('' !== $p) {
                        $permits[] = strtolower(ltrim($p, '\\'));
                    }
                }
            }
            $this->permitsByLine[$line] = $permits;
            $pieces[] = $kind.' '.$name.' ';
            $offset = $matchOffset + strlen($m[0][0]);
        }
        $out = [] === $pieces ? $code : implode('', $pieces).substr($code, $offset);

        return [$out, $this->permitsByLine];
    }

    /**
     * @return array<int, list<string>>
     */
    public function permitsByLine(): array
    {
        return $this->permitsByLine;
    }
}
