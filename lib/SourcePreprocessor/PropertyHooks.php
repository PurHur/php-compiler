<?php

declare(strict_types=1);

namespace PHPCompiler\SourcePreprocessor;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\PropertyHookRejector;

/**
 * Strip PHP 8.4 property-hook blocks for nikic/php-parser v4 and inject hook methods.
 *
 * php-src: Zend/zend_compile.c property hook lowering (issue #3145, #5404 short get/set =>).
 */
final class PropertyHooks
{
    /** Legacy message retained for tests/docs; static hooks compile since #6931 (PHP 8.4, zend_property_hooks.c). */
    public const STATIC_HOOK_COMPILE_ERROR = 'Cannot declare hooks for static property';

    /** php-src: Zend/zend_compile.c — `private(set)` decl + hook block requires set hook (#12203). */
    public const ASYMMETRIC_DECL_SET_REQUIRES_SET_HOOK_MESSAGE = 'syntax error, unexpected token ")", expecting amp';

    private const SET_METHOD_PREFIX = '__phpc_property_set_';
    private const GET_METHOD_PREFIX = '__phpc_property_get_';
    private const UNSET_METHOD_PREFIX = '__phpc_property_unset_';

    /** @var array<string, array<string, array{set?: string, get?: string}>> lcClass => prop => hook method names */
    private array $registry = [];

    /**
     * @return array{0: string, 1: array<string, array<string, array{set?: string, get?: string}>>}
     */
    public function process(string $code, string $filename = 'unknown'): array
    {
        $this->registry = [];
        $offset = 0;
        $len = strlen($code);
        while ($offset < $len) {
            $decl = $this->findNextDeclarable($code, $offset);
            if (null === $decl) {
                break;
            }
            [$declPos, $declKind, $declName] = $decl;
            $braceOpen = strpos($code, '{', $declPos);
            if (false === $braceOpen) {
                break;
            }
            $span = $this->matchingBraceSpan($code, $braceOpen);
            if (null === $span) {
                $offset = $braceOpen + 1;
                continue;
            }
            [$bodyStart, $bodyEnd] = $span;
            $body = substr($code, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $header = substr($code, $declPos, $braceOpen - $declPos);
            $isAbstractClass = 'class' === $declKind
                && (bool) preg_match('/\babstract\s+(?:readonly\s+)?class\b/i', $header);
            $processedBody = $this->processClassBody(
                $body,
                strtolower($declName),
                $declName,
                $filename,
                $bodyStart + 1,
                $code,
                $declKind,
                $isAbstractClass
            );
            $code = substr($code, 0, $bodyStart + 1).$processedBody.substr($code, $bodyEnd);
            $offset = $bodyStart + 1 + strlen($processedBody);
        }

        return [$code, $this->registry];
    }

    /**
     * @return array{0: int, 1: string, 2: string}|null [position, kind, name]
     */
    private function findNextDeclarable(string $code, int $from): ?array
    {
        $len = strlen($code);
        $searchFrom = $from;
        while ($searchFrom < $len) {
            $candidate = null;
            foreach ([
                'class' => '/\bclass\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/',
                'interface' => '/\binterface\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/',
                'trait' => '/\btrait\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/',
            ] as $kind => $pattern) {
                if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE, $searchFrom)) {
                    continue;
                }
                $pos = $m[0][1];
                if (null === $candidate || $pos < $candidate[0]) {
                    $candidate = [$pos, $kind, $m[1][0], $pos + strlen($m[0][0])];
                }
            }
            if (null === $candidate) {
                return null;
            }
            [$pos, $kind, $name, $nameEnd] = $candidate;
            if ($this->offsetInNonCodeContext($code, $pos)) {
                $searchFrom = $pos + 1;

                continue;
            }
            if ($this->isDeclarableHeader($code, $nameEnd)) {
                return [$pos, $kind, $name];
            }
            $searchFrom = $pos + 1;
        }

        return null;
    }

    /**
     * True when $offset sits inside a string literal or comment (#7030 — do not rewrite eval() strings).
     */
    private function offsetInNonCodeContext(string $code, int $offset): bool
    {
        $len = strlen($code);
        $i = 0;
        $inString = false;
        $stringQuote = '';
        $inLineComment = false;
        $inBlockComment = false;
        while ($i < $offset && $i < $len) {
            if ($inLineComment) {
                if ("\n" === $code[$i]) {
                    $inLineComment = false;
                }
                ++$i;

                continue;
            }
            if ($inBlockComment) {
                if ('*' === $code[$i] && $i + 1 < $len && '/' === $code[$i + 1]) {
                    $inBlockComment = false;
                    $i += 2;

                    continue;
                }
                ++$i;

                continue;
            }
            if ($inString) {
                if ('\\' === $code[$i]) {
                    $i += 2;

                    continue;
                }
                if ($code[$i] === $stringQuote) {
                    $inString = false;
                }
                ++$i;

                continue;
            }
            if ('/' === $code[$i] && $i + 1 < $len) {
                if ('/' === $code[$i + 1]) {
                    $inLineComment = true;
                    $i += 2;

                    continue;
                }
                if ('*' === $code[$i + 1]) {
                    $inBlockComment = true;
                    $i += 2;

                    continue;
                }
            }
            if ('#' === $code[$i]) {
                $inLineComment = true;
                ++$i;

                continue;
            }
            if ('"' === $code[$i] || '\'' === $code[$i]) {
                $inString = true;
                $stringQuote = $code[$i];
                ++$i;

                continue;
            }
            ++$i;
        }

        return $inString || $inLineComment || $inBlockComment;
    }

    private function isDeclarableHeader(string $code, int $from): bool
    {
        $len = strlen($code);
        $i = $from;
        while ($i < $len && ctype_space($code[$i])) {
            ++$i;
        }
        if ($i >= $len) {
            return false;
        }
        if ('{' === $code[$i]) {
            return true;
        }

        return (bool) preg_match(
            '/^(?:extends|implements|sealed|readonly)\b[\s\S]*?\{/i',
            substr($code, $i)
        );
    }

    /**
     * True when a hooked `$prop { ... }` sits in a constructor promoted parameter list (#7313).
     */
    private function isPromotedConstructorParam(
        string $body,
        int $declStart,
        int $hookClose,
        string $declPrefix,
        string $propDeclHead
    ): bool {
        if (!preg_match('/\b(public|protected|private)\b/', $declPrefix.$propDeclHead)) {
            return false;
        }
        $prefix = substr($body, 0, $declStart);
        if (!preg_match('/\bfunction\s+__construct\s*\(/s', $prefix, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $constructOpen = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $segment = substr($body, $constructOpen, $hookClose - $constructOpen + 1);
        $depth = 0;
        $len = strlen($segment);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $segment[$i];
            if ('(' === $ch) {
                ++$depth;
            } elseif (')' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return false;
                }
            }
        }

        return $depth > 0;
    }

    /**
     * @return array{0: int, 1: int}|null [openBracePos, closeBracePos]
     */
    private function matchingBraceSpan(string $code, int $openPos): ?array
    {
        $depth = 0;
        $len = strlen($code);
        $inString = false;
        $stringChar = '';
        for ($i = $openPos; $i < $len; ++$i) {
            $ch = $code[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('{' === $ch) {
                ++$depth;
            } elseif ('}' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return [$openPos, $i];
                }
            }
        }

        return null;
    }

    /**
     * Find `$prop` optionally followed by `= <expr>` then property-hook `{` (#9945, Zend/zend_compile.c).
     *
     * @return array{0: string, 1: int, 2: int}|null [prop name, `$` offset, hook `{` offset]
     */
    private function findNextPropertyHookDecl(string $body, int $offset): ?array
    {
        $len = strlen($body);
        while ($offset < $len) {
            if (!preg_match('/\$(\w+)/', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
                return null;
            }
            $prop = $m[1][0];
            $varStart = $m[0][1];
            $afterVar = $varStart + strlen($m[0][0]);
            if ($this->isOffsetInComment($body, $varStart)) {
                $offset = $afterVar + 1;
                continue;
            }
            if (
                $this->isInsideFunctionBody($body, $varStart)
                && !$this->isPromotedConstructorParamVar($body, $varStart)
            ) {
                $offset = $afterVar + 1;
                continue;
            }
            $declLookback = $varStart - 1;
            while ($declLookback >= 0 && ctype_space($body[$declLookback])) {
                --$declLookback;
            }
            // Assignment inside a condition/paren expr, not a property hook (`($dot = …) {`).
            if ($declLookback >= 0 && '(' === $body[$declLookback]) {
                $offset = $afterVar + 1;
                continue;
            }
            $i = $afterVar;
            while ($i < $len && ctype_space($body[$i])) {
                ++$i;
            }
            if ($i >= $len) {
                return null;
            }
            if ('=' === $body[$i]) {
                // Comparisons, fat arrows, and compound assigns are not hook defaults.
                if (
                    ($i > 0 && '=' === $body[$i - 1])
                    || ($i + 1 < $len && '=' === $body[$i + 1])
                    || ($i > 0 && '>' === $body[$i - 1])
                    || ($i + 1 < $len && '>' === $body[$i + 1])
                    || ($i > 0 && '!' === $body[$i - 1])
                    || ($i > 0 && '<' === $body[$i - 1])
                ) {
                    $offset = $afterVar + 1;
                    continue;
                }
                $hookOpen = $this->scanToHookOpenBrace($body, $i + 1);
                if (null !== $hookOpen) {
                    $assignSlice = substr($body, $i + 1, $hookOpen - $i - 1);
                    if (preg_match('/\b(match|function)\b/', $assignSlice)) {
                        $offset = $afterVar + 1;
                        continue;
                    }
                    if ($this->isFunctionBodyBraceAfterParamDefault($body, $hookOpen)) {
                        $offset = $afterVar + 1;
                        continue;
                    }
                    if ($this->isForLoopHeaderBraceAfterAssignment($body, $hookOpen)) {
                        $offset = $afterVar + 1;
                        continue;
                    }

                    return [$prop, $varStart, $hookOpen];
                }
                $offset = $afterVar + 1;
                continue;
            }
            if ('{' === $body[$i]) {
                return [$prop, $varStart, $i];
            }
            $offset = $afterVar + 1;
        }

        return null;
    }


    private function isOffsetInComment(string $body, int $offset): bool
    {
        $len = strlen($body);
        $inString = false;
        $stringChar = '';
        $inBlockComment = false;
        for ($i = 0; $i < $offset && $i < $len; ++$i) {
            $ch = $body[$i];
            $next = $i + 1 < $len ? $body[$i + 1] : '';
            if ($inBlockComment) {
                if ('*' === $ch && '/' === $next) {
                    $inBlockComment = false;
                    ++$i;
                }
                continue;
            }
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('/' === $ch && '/' === $next) {
                $lineEnd = strpos($body, "\n", $i);
                if (false === $lineEnd) {
                    $lineEnd = $len;
                }
                if ($offset < $lineEnd) {
                    return true;
                }
                $i = $lineEnd;
                continue;
            }
            if ('/' === $ch && '*' === $next) {
                $inBlockComment = true;
                ++$i;
            }
        }

        return $inBlockComment;
    }

    /**
     * For-loop increment clauses look like `$handler = $handler->parent) {` (#1492 bootstrap M4).
     */
    private function isForLoopHeaderBraceAfterAssignment(string $body, int $hookOpenPos): bool
    {
        if ($hookOpenPos <= 0 || '{' !== $body[$hookOpenPos]) {
            return false;
        }
        $i = $hookOpenPos - 1;
        while ($i >= 0 && ctype_space($body[$i])) {
            --$i;
        }
        if ($i < 0 || ')' !== $body[$i]) {
            return false;
        }
        $depth = 0;
        for ($j = $i; $j >= 0; --$j) {
            $ch = $body[$j];
            if (')' === $ch) {
                ++$depth;
            } elseif ('(' === $ch) {
                --$depth;
                if (0 === $depth) {
                    $before = rtrim(substr($body, 0, $j));

                    return (bool) preg_match('/\bfor\s*$/s', $before);
                }
            }
        }

        return false;
    }

    /**
     * Param defaults before a function/closure body look like `$x = 1) {` or `$x = 's'): string {` (#9729, bootstrap spine).
     */
    private function isFunctionBodyBraceAfterParamDefault(string $body, int $hookOpenPos): bool
    {
        if ($hookOpenPos <= 0 || '{' !== $body[$hookOpenPos]) {
            return false;
        }
        $i = $hookOpenPos - 1;
        while ($i >= 0 && ctype_space($body[$i])) {
            --$i;
        }
        if ($i < 0) {
            return false;
        }
        if (')' !== $body[$i]) {
            while ($i >= 0 && ':' !== $body[$i]) {
                if (!preg_match('/[a-zA-Z0-9_\\\\|&?<>,()\\s]/', $body[$i])) {
                    return false;
                }
                --$i;
            }
            if ($i < 0 || ':' !== $body[$i]) {
                return false;
            }
            --$i;
            while ($i >= 0 && ctype_space($body[$i])) {
                --$i;
            }
            if ($i < 0 || ')' !== $body[$i]) {
                return false;
            }
        }
        $depth = 0;
        for ($j = $i; $j >= 0; --$j) {
            $ch = $body[$j];
            if (')' === $ch) {
                ++$depth;
            } elseif ('(' === $ch) {
                --$depth;
                if (0 === $depth) {
                    $before = rtrim(substr($body, 0, $j));

                    return (bool) preg_match('/\bfunction\s*(?:&\s*)?[\w\\\\]*\s*$/s', $before);
                }
            }
        }

        return false;
    }

    /**
     * Scan from $start through an optional default initializer to the hook-block `{`.
     */
    private function scanToHookOpenBrace(string $body, int $start): ?int
    {
        $len = strlen($body);
        $depthParen = 0;
        $depthBrace = 0;
        $depthBracket = 0;
        $inString = false;
        $stringChar = '';
        for ($i = $start; $i < $len; ++$i) {
            if ($i + 2 < $len && '<<<' === substr($body, $i, 3)) {
                $afterHeredoc = $this->skipHeredocNowdoc($body, $i);
                if (null !== $afterHeredoc) {
                    $i = $afterHeredoc - 1;
                    continue;
                }
            }
            $ch = $body[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depthParen;
            } elseif (')' === $ch && $depthParen > 0) {
                --$depthParen;
            } elseif (';' === $ch && 0 === $depthParen && 0 === $depthBrace && 0 === $depthBracket) {
                return null;
            } elseif ('{' === $ch) {
                if (0 === $depthParen && 0 === $depthBrace && 0 === $depthBracket) {
                    return $i;
                }
                ++$depthBrace;
            } elseif ('}' === $ch && $depthBrace > 0) {
                --$depthBrace;
            } elseif ('[' === $ch) {
                ++$depthBracket;
            } elseif (']' === $ch && $depthBracket > 0) {
                --$depthBracket;
            }
        }

        return null;
    }

    /**
     * Local `$var = …` inside a method body is not a hooked property decl (#1492 bootstrap spine).
     */
    private function isInsideFunctionBody(string $body, int $offset): bool
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; --$i) {
            $ch = $body[$i];
            if ('}' === $ch) {
                ++$depth;
            } elseif ('{' === $ch) {
                if (0 === $depth) {
                    $before = rtrim(substr($body, 0, $i));

                    return (bool) preg_match(
                        '/\bfunction\s*(?:&\s*)?[\w\\\\]*\s*\([^)]*\)\s*(?::\s*[^ {]+)?\s*$/s',
                        $before
                    );
                }
                --$depth;
            }
        }

        return false;
    }

    private function isPromotedConstructorParamVar(string $body, int $varStart): bool
    {
        $prefix = substr($body, 0, $varStart);
        if (!preg_match('/\bfunction\s+__construct\s*\(/s', $prefix)) {
            return false;
        }
        $lineStart = strrpos(substr($body, 0, $varStart), "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $linePrefix = substr($body, $lineStart, $varStart - $lineStart);

        return (bool) preg_match('/\b(public|protected|private)\b/', $linePrefix);
    }

    /**
     * @return int|null position after closing heredoc/nowdoc delimiter line
     */
    private function skipHeredocNowdoc(string $body, int $pos): ?int
    {
        $len = strlen($body);
        if ($pos + 3 > $len || '<<<' !== substr($body, $pos, 3)) {
            return null;
        }
        $i = $pos + 3;
        if ($i >= $len) {
            return null;
        }
        $label = '';
        if ("'" === $body[$i] || '"' === $body[$i]) {
            $quote = $body[$i];
            ++$i;
            while ($i < $len && (ctype_alnum($body[$i]) || '_' === $body[$i])) {
                $label .= $body[$i];
                ++$i;
            }
            if ($i >= $len || $body[$i] !== $quote) {
                return null;
            }
            ++$i;
        } else {
            while ($i < $len && (ctype_alnum($body[$i]) || '_' === $body[$i])) {
                $label .= $body[$i];
                ++$i;
            }
        }
        if ('' === $label) {
            return null;
        }
        while ($i < $len && (' ' === $body[$i] || "\t" === $body[$i])) {
            ++$i;
        }
        if ($i < $len && "\r" === $body[$i]) {
            ++$i;
        }
        if ($i < $len && "\n" === $body[$i]) {
            ++$i;
        }
        while ($i < $len) {
            $lineStart = $i;
            while ($i < $len && "\n" !== $body[$i] && "\r" !== $body[$i]) {
                ++$i;
            }
            $line = substr($body, $lineStart, $i - $lineStart);
            $stripped = rtrim($line, "\r");
            if (preg_match('/^(\s*)('.preg_quote($label, '/').')(\s*;)?\s*$/', $stripped, $m)
                && ('' === $m[1] || ctype_space($m[1]))) {
                if ($i < $len && "\r" === $body[$i]) {
                    ++$i;
                }
                if ($i < $len && "\n" === $body[$i]) {
                    ++$i;
                }

                return $i;
            }
            if ($i < $len) {
                if ("\r" === $body[$i]) {
                    ++$i;
                }
                if ($i < $len && "\n" === $body[$i]) {
                    ++$i;
                }
            }
        }

        return null;
    }

    private function processClassBody(
        string $body,
        string $lcClass,
        string $classDisplay,
        string $filename,
        int $bodyOffsetInFile,
        string $fullCode,
        string $declKind = 'class',
        bool $isAbstractClass = false
    ): string {
        $isConcreteClass = 'class' === $declKind && !$isAbstractClass;
        $injections = [];
        /** @var list<array{0: int, 1: int}> */
        $removeSpans = [];
        $offset = 0;
        $out = '';
        while (null !== ($hookDecl = $this->findNextPropertyHookDecl($body, $offset))) {
            [$prop, $declStart, $hookOpen] = $hookDecl;
            $span = $this->matchingBraceSpan($body, $hookOpen);
            if (null === $span) {
                $out .= substr($body, $offset);
                break;
            }
            [$open, $close] = $span;
            $hookSource = substr($body, $open + 1, $close - $open - 1);
            $declPrefix = substr($body, $offset, $declStart - $offset);
            $propDeclHead = rtrim(substr($body, $declStart, $hookOpen - $declStart));
            $isAbstractHook = (bool) preg_match('/\babstract\b/', $declPrefix.$propDeclHead);
            $isInterfaceHook = 'interface' === $declKind;
            if ($isAbstractHook) {
                $declPrefix = preg_replace('/\babstract\s+/', '', $declPrefix) ?? $declPrefix;
                $propDeclHead = preg_replace('/\babstract\s+/', '', $propDeclHead) ?? $propDeclHead;
            }
            $isStatic = (bool) preg_match('/\bstatic\b/', $declPrefix.$propDeclHead);
            $isPromotedCtorParam = $this->isPromotedConstructorParam(
                $body,
                $declStart,
                $close,
                $declPrefix,
                $propDeclHead
            );
            $propDecl = preg_replace('/\s+$/', '', $propDeclHead) ?? $propDeclHead;
            if (!$isPromotedCtorParam && !str_ends_with($propDecl, ';')) {
                $propDecl .= ';';
            }
            $isTraitDecl = 'trait' === $declKind;
            $skipSemicolonRequiredHooks = $isConcreteClass
                && $this->isImplicitAsymmetricBackingHookSource($hookSource);
            $propertyType = $this->propertyTypeFromDeclHead($declPrefix.$propDeclHead);
            [$methods, $usesBacking, $trailing, $asymmetricSetVis] = $this->lowerHooks(
                $hookSource,
                $prop,
                $lcClass,
                $isStatic,
                $skipSemicolonRequiredHooks,
                $propertyType
            );
            $this->rejectAsymmetricDeclSetWithoutSetHook(
                $declPrefix.$propDeclHead,
                $hookSource,
                $lcClass,
                $prop,
                $filename,
                $fullCode,
                $bodyOffsetInFile + $declStart
            );
            if (null !== $asymmetricSetVis) {
                $marker = '/*phpc-asymmetric-set:'.$asymmetricSetVis.'*/ ';
                if (preg_match('/^(\s*)/', $declPrefix, $indentM)) {
                    $declPrefix = $indentM[1].$marker.ltrim($declPrefix);
                } else {
                    $declPrefix = $marker.$declPrefix;
                }
            }
            $sameNameBacking = $usesBacking && $this->hookTouchesBacking($hookSource, $prop, $isStatic);
            $nextOffset = $close + 1;
            $initializer = '';
            $hasInlineInitializer = $this->propertyDeclHeadHasInlineInitializer($propDeclHead);
            if ($sameNameBacking) {
                if (!$hasInlineInitializer) {
                    $backingDecl = $this->consumeSameNameBackingFieldDecl($body, $nextOffset, $prop);
                    if (null !== $backingDecl) {
                        [$nextOffset, $initializer] = $backingDecl;
                    }
                    // Detached same-name fields are duplicate declarations — only adjacent
                    // backing merges (#7031). Non-adjacent must fail compile (#10393, zend_compile.c).
                }
                $mergedDecl = rtrim($propDeclHead);
                if ('' !== $initializer) {
                    $mergedDecl .= ' '.$initializer;
                }
                if (!$isPromotedCtorParam && !str_ends_with($mergedDecl, ';')) {
                    $mergedDecl .= ';';
                }
                $out .= $declPrefix.$mergedDecl;
            } else {
                $out .= $declPrefix.$propDecl;
            }
            $trailing = trim($trailing);
            if ('' !== $trailing) {
                $out .= "\n    ".$trailing;
            }
            $isTraitAbstractHook = $isTraitDecl && [] === $methods;
            $propMeta = $this->registry[$lcClass][$prop] ?? [];
            $hasSemicolonRequirements = !empty($propMeta['requiresGet'])
                || !empty($propMeta['requiresSet'])
                || !empty($propMeta['requiresUnset']);
            $isSemicolonOnlyHook = [] === $methods && $hasSemicolonRequirements;
            if (([] !== $methods && !$usesBacking) || $isAbstractHook || $isInterfaceHook || $isTraitAbstractHook || $isSemicolonOnlyHook) {
                if (!isset($this->registry[$lcClass][$prop])) {
                    $this->registry[$lcClass][$prop] = [];
                }
                if ($isAbstractHook || $isInterfaceHook || $isTraitAbstractHook || $isSemicolonOnlyHook) {
                    $this->registry[$lcClass][$prop]['abstract'] = true;
                }
                if ([] === $methods || !$usesBacking || $isInterfaceHook || $isSemicolonOnlyHook) {
                    $this->registry[$lcClass][$prop]['virtual'] = true;
                }
            }
            $injections = array_merge($injections, $methods);
            $offset = $nextOffset;
        }
        $out .= $this->copyBodySegment($body, $offset, strlen($body), $removeSpans);
        if ([] !== $injections) {
            $out .= "\n".implode("\n", $injections)."\n";
        }

        return $out;
    }

    /**
     * @return array{0: list<string>, 1: bool, 2: string, 3: ?string} method source chunks, backing use, trailing decls, asymmetric set visibility
     */
    /**
     * Concrete `{ get; private set; }` uses implicit backing field — not abstract obligations (#7148).
     */
    private function isImplicitAsymmetricBackingHookSource(string $hookSource): bool
    {
        $rest = trim($hookSource);
        if (!preg_match('/^get\s*;/', $rest)) {
            return false;
        }
        $rest = trim(preg_replace('/^get\s*;/', '', $rest, 1) ?? $rest);
        if ('' === $rest) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:(public|protected|private)\s+set\s*;|set\s*\(\s*(public|protected|private)\s*\)\s*;|(?:public|protected|private)\s*\(\s*set\s*\)\s*;)\s*$/s',
            $rest
        );
    }

    private function lowerHooks(
        string $hookSource,
        string $prop,
        string $lcClass,
        bool $isStatic = false,
        bool $skipSemicolonRequiredHooks = false,
        ?string $propertyType = null
    ): array {
        $methods = [];
        $usesBacking = false;
        $asymmetricSetVisibility = null;
        $rest = trim($hookSource);
        while ('' !== $rest) {
            $rest = ltrim($rest);
            if (preg_match('/^get\s*;/s', $rest)) {
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresGet');
                }
                $rest = preg_replace('/^get\s*;/', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^(public|protected|private)\s+set\s*;/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresSet');
                }
                $rest = preg_replace('/^(public|protected|private)\s+set\s*;/i', '', $rest, 1) ?? $rest;
                continue;
            }
            // php-src: Zend/zend_compile.c — `private(set);` in hook block (#9872, PHP 8.4 asymmetric visibility).
            if (preg_match('/^(public|protected|private)\s*\(\s*set\s*\)\s*;/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresSet');
                }
                $rest = preg_replace('/^(public|protected|private)\s*\(\s*set\s*\)\s*;/i', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^set\s*;/s', $rest)) {
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresSet');
                }
                $rest = preg_replace('/^set\s*;/', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*;/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresSet');
                }
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*;/i', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^unset\s*;/s', $rest)) {
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresUnset');
                }
                $rest = preg_replace('/^unset\s*;/', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^get\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^get\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($expr, $prop, $isStatic);
                $this->registerHookBacking($lcClass, $prop, 'get', $expr, $isStatic);
                $body = '{ return '.$expr.'; }';
                $method = self::GET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType);
                $this->registerHook($lcClass, $prop, 'get', $method, $isStatic);
                continue;
            }
            if (preg_match('/^get\s*\{/s', $rest)) {
                $rest = preg_replace('/^get\s*/', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
                $method = self::GET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType);
                $this->registerHook($lcClass, $prop, 'get', $method, $isStatic);
                continue;
            }
            if (preg_match('/^set\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^set\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetArrowHook($lcClass, $prop, $isStatic, rtrim($expr), $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^(public|protected|private)\s+set\s*=>\s*/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^(public|protected|private)\s+set\s*=>\s*/i', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetArrowHook($lcClass, $prop, $isStatic, rtrim($expr), $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*=>\s*/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*=>\s*/i', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetArrowHook($lcClass, $prop, $isStatic, rtrim($expr), $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*\(/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*/i', '', $rest, 1) ?? $rest;
                if (!preg_match('/^\(([^)]*)\)\s*\{/s', $rest, $pm)) {
                    break;
                }
                $params = trim($pm[1]);
                $rest = substr($rest, strlen($pm[0]) - 1);
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, $params, $body, $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*\{/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*/i', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, '$value', $body, $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^(public|protected|private)\s+set\s*\(/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^(public|protected|private)\s+set\s*/i', '', $rest, 1) ?? $rest;
                if (!preg_match('/^\(([^)]*)\)\s*\{/s', $rest, $pm)) {
                    break;
                }
                $params = trim($pm[1]);
                $rest = substr($rest, strlen($pm[0]) - 1);
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, $params, $body, $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^(public|protected|private)\s+set\s*\{/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^(public|protected|private)\s+set\s*/i', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, '$value', $body, $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^set\s*\(/s', $rest)) {
                $rest = preg_replace('/^set\s*/', '', $rest, 1) ?? $rest;
                if (!preg_match('/^\(([^)]*)\)\s*\{/s', $rest, $pm)) {
                    break;
                }
                $params = trim($pm[1]);
                $rest = substr($rest, strlen($pm[0]) - 1);
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, $params, $body, $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^set\s*\{/s', $rest)) {
                $rest = preg_replace('/^set\s*/', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, '$value', $body, $usesBacking, $propertyType)
                );
                continue;
            }
            if (preg_match('/^unset\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^unset\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $expr = rtrim($expr);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($expr, $prop, $isStatic);
                $body = '{ '.$expr.'; }';
                $method = self::UNSET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType);
                $this->registerHook($lcClass, $prop, 'unset', $method, $isStatic);
                continue;
            }
            if (preg_match('/^unset\s*\{/s', $rest)) {
                $rest = preg_replace('/^unset\s*/', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
                $method = self::UNSET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType);
                $this->registerHook($lcClass, $prop, 'unset', $method, $isStatic);
                continue;
            }
            break;
        }

        return [$methods, $usesBacking, trim($rest), $asymmetricSetVisibility];
    }

    /**
     * @return list<string>
     */
    private function lowerSetArrowHook(
        string $lcClass,
        string $prop,
        bool $isStatic,
        string $expr,
        bool &$usesBacking,
        ?string $propertyType = null
    ): array {
        if ($this->setArrowExprUsesStatementForm($expr, $isStatic)) {
            $usesBacking = $usesBacking || $this->hookTouchesBacking($expr, $prop, $isStatic);
            $this->registerHookBacking($lcClass, $prop, 'set', $expr, $isStatic);
            $body = '{ '.$expr.'; }';
        } else {
            $backing = $isStatic ? 'self::$'.$prop : '$this->'.$prop;
            $usesBacking = true;
            $body = '{ '.$backing.' = ('.$expr.'); }';
        }
        $method = self::SET_METHOD_PREFIX.$prop;
        $this->registerHook($lcClass, $prop, 'set', $method, $isStatic);

        return [$this->hookMethodDecl($isStatic, $method, '$value', $body, $propertyType)];
    }

    /**
     * @return list<string>
     */
    private function lowerSetBlockHook(
        string $lcClass,
        string $prop,
        bool $isStatic,
        string $params,
        string $body,
        bool &$usesBacking,
        ?string $propertyType = null
    ): array {
        $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
        $this->registerHookBackingFromBody($lcClass, $prop, 'set', $body, $isStatic);
        $method = self::SET_METHOD_PREFIX.$prop;
        $this->registerHook($lcClass, $prop, 'set', $method, $isStatic);

        return [$this->hookMethodDecl($isStatic, $method, $params, $body, $propertyType)];
    }

    /**
     * True when the hooked property decl already carries `= <expr>` before the hook block (#9945, #11594).
     */
    private function propertyDeclHeadHasInlineInitializer(string $propDeclHead): bool
    {
        return (bool) preg_match('/=\s*\S/', $propDeclHead);
    }

    /**
     * When hooks read/write `$this->prop`, merge only the immediately following same-name
     * field decl (#7031). Detached duplicates fail at compile (#10393, zend_compile.c).
     *
     * @return array{0: int, 1: string}|null [offset after decl, initializer including `=`]
     */
    private function consumeSameNameBackingFieldDecl(string $body, int $offset, string $prop): ?array
    {
        $remainder = substr($body, $offset);
        if (!preg_match(
            '/^\s*(?:(?:public|protected|private|static|readonly)\s+)*'
            .'(?:[\w\\\\|]+(?:\s*\[\s*\])?\s+)+'
            .'\$'.preg_quote($prop, '/').'\s*(=\s*[^;]+)?;/',
            $remainder,
            $m
        )) {
            return null;
        }

        $initializer = isset($m[1]) ? trim($m[1]) : '';

        return [$offset + strlen($m[0]), $initializer];
    }

    /**
     * @param list<array{0: int, 1: int}> $removeSpans
     */
    private function copyBodySegment(string $body, int $from, int $to, array $removeSpans): string
    {
        if ($from >= $to) {
            return '';
        }
        if ([] === $removeSpans) {
            return substr($body, $from, $to - $from);
        }
        $result = '';
        $pos = $from;
        foreach ($removeSpans as [$start, $end]) {
            if ($end <= $from || $start >= $to) {
                continue;
            }
            $clipStart = max($start, $from);
            $clipEnd = min($end, $to);
            if ($pos < $clipStart) {
                $result .= substr($body, $pos, $clipStart - $pos);
            }
            $pos = max($pos, $clipEnd);
        }
        if ($pos < $to) {
            $result .= substr($body, $pos, $to - $pos);
        }

        return $result;
    }

    private function hookTouchesBacking(string $source, string $prop, bool $isStatic): bool
    {
        $pattern = $isStatic
            ? '/\bself::\$'.preg_quote($prop, '/').'\b/'
            : '/\$this->'.preg_quote($prop, '/').'\b/';

        return (bool) preg_match($pattern, $source);
    }

    /** Zend short set => expr: assignment statements run as-is; other exprs assign to backing (#6424). */
    private function setArrowExprUsesStatementForm(string $expr, bool $isStatic): bool
    {
        $expr = ltrim($expr);
        if (preg_match('/^\$this->\w+\s*=/', $expr)) {
            return true;
        }
        if ($isStatic && preg_match('/^self::\$\w+\s*=/', $expr)) {
            return true;
        }

        return false;
    }

    private function hookMethodDecl(
        bool $isStatic,
        string $method,
        string $params,
        string $body,
        ?string $propertyType = null
    ): string {
        $static = $isStatic ? 'static ' : '';
        $typedParams = $params;
        $returnSuffix = '';
        if ($isStatic && null !== $propertyType && '' !== $propertyType) {
            if (str_starts_with($method, self::GET_METHOD_PREFIX)) {
                $returnSuffix = ': '.$propertyType;
            } elseif (str_starts_with($method, self::SET_METHOD_PREFIX)) {
                $typedParams = $this->typedSetHookParams($params, $propertyType);
                $returnSuffix = ': void';
            } elseif (str_starts_with($method, self::UNSET_METHOD_PREFIX)) {
                $returnSuffix = ': void';
            }
        }
        if ('' !== $typedParams) {
            return "    public {$static}function {$method}({$typedParams}){$returnSuffix} {$body}";
        }

        return "    public {$static}function {$method}(){$returnSuffix} {$body}";
    }

    private function typedSetHookParams(string $params, string $propertyType): string
    {
        $params = trim($params);
        if ('$value' === $params) {
            return $propertyType.' $value';
        }
        if (preg_match('/^mixed\s+\$value$/', $params)) {
            return $propertyType.' $value';
        }

        return $params;
    }

    private function propertyTypeFromDeclHead(string $propDeclHead): ?string
    {
        $s = preg_replace(
            '/\b(public|protected|private|static|readonly|abstract)\s+/',
            '',
            $propDeclHead
        ) ?? $propDeclHead;
        $s = trim($s);
        if (!preg_match('/^(.+?)\s+\$/', $s, $m)) {
            return null;
        }
        $type = trim($m[1]);

        return '' !== $type ? $type : null;
    }

    /**
     * @param 'requiresGet'|'requiresSet'|'requiresUnset' $flag
     */
    private function registerRequiredHook(string $lcClass, string $prop, string $flag): void
    {
        if (!isset($this->registry[$lcClass][$prop])) {
            $this->registry[$lcClass][$prop] = [];
        }
        $this->registry[$lcClass][$prop][$flag] = true;
    }

    /**
     * @param 'get'|'set'|'unset' $kind
     */
    private function registerHook(string $lcClass, string $prop, string $kind, string $method, bool $isStatic): void
    {
        if (!isset($this->registry[$lcClass][$prop])) {
            $this->registry[$lcClass][$prop] = [];
        }
        $this->registry[$lcClass][$prop][$kind] = $method;
        if ($isStatic) {
            $this->registry[$lcClass][$prop]['static'] = true;
        }
    }

    /**
     * Record `$this->field` / `self::$field` read/write targets for foreach-by-ref (#6435).
     *
     * @param 'get'|'set' $kind
     */
    private function registerHookBacking(string $lcClass, string $prop, string $kind, string $expr, bool $isStatic): void
    {
        $expr = trim($expr);
        if ($isStatic) {
            if (preg_match('/^self::\$(\w+)\s*(?:=\s*|$)/', $expr, $m)) {
                $key = 'get' === $kind ? 'getBacking' : 'setBacking';
                $this->registry[$lcClass][$prop][$key] = $m[1];
            }

            return;
        }
        if ('get' === $kind && preg_match('/^\$this->(\w+)$/', $expr, $m)) {
            $this->registry[$lcClass][$prop]['getBacking'] = $m[1];

            return;
        }
        if ('set' === $kind && preg_match('/^\$this->(\w+)\s*=/', $expr, $m)) {
            $this->registry[$lcClass][$prop]['setBacking'] = $m[1];
        }
    }

    /**
     * Record separate backing field targets from hook block bodies (#6635).
     *
     * @param 'get'|'set' $kind
     */
    private function registerHookBackingFromBody(
        string $lcClass,
        string $prop,
        string $kind,
        string $body,
        bool $isStatic
    ): void {
        if ($isStatic) {
            if (preg_match('/\bself::\$(\w+)\s*=/', $body, $m) && strcasecmp($m[1], $prop) !== 0) {
                $key = 'get' === $kind ? 'getBacking' : 'setBacking';
                $this->registry[$lcClass][$prop][$key] = $m[1];
            }

            return;
        }
        if (preg_match('/\$this->(\w+)\s*=/', $body, $m) && strcasecmp($m[1], $prop) !== 0) {
            $key = 'get' === $kind ? 'getBacking' : 'setBacking';
            $this->registry[$lcClass][$prop][$key] = $m[1];
        }
    }

    /**
     * @return array{0: string, 1: string} [expression/statement, remainder after ';']
     */
    private function takeUntilSemicolon(string $source): array
    {
        $source = ltrim($source);
        $len = strlen($source);
        $depthParen = 0;
        $depthBrace = 0;
        $depthBracket = 0;
        $inString = false;
        $stringChar = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $source[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depthParen;
            } elseif (')' === $ch && $depthParen > 0) {
                --$depthParen;
            } elseif ('{' === $ch) {
                ++$depthBrace;
            } elseif ('}' === $ch && $depthBrace > 0) {
                --$depthBrace;
            } elseif ('[' === $ch) {
                ++$depthBracket;
            } elseif (']' === $ch && $depthBracket > 0) {
                --$depthBracket;
            } elseif (';' === $ch && 0 === $depthParen && 0 === $depthBrace && 0 === $depthBracket) {
                $chunk = rtrim(substr($source, 0, $i));

                return [$chunk, ltrim(substr($source, $i + 1))];
            }
        }

        return [rtrim($source), ''];
    }

    /**
     * @return array{0: string, 1: string} [brace block including braces, remainder]
     */
    private function takeBraceBody(string $source): array
    {
        $source = ltrim($source);
        if (!str_starts_with($source, '{')) {
            return ['{ }', ''];
        }
        $span = $this->matchingBraceSpan($source, 0);
        if (null === $span) {
            return ['{ }', ''];
        }
        [$open, $close] = $span;
        $block = substr($source, $open, $close - $open + 1);

        return [$block, ltrim(substr($source, $close + 1))];
    }

    public static function setHookMethodName(string $property): string
    {
        return self::SET_METHOD_PREFIX.$property;
    }

    public static function getHookMethodName(string $property): string
    {
        return self::GET_METHOD_PREFIX.$property;
    }

    public static function unsetHookMethodName(string $property): string
    {
        return self::UNSET_METHOD_PREFIX.$property;
    }

    public static function propertyNameFromSetHookMethod(string $methodLc): ?string
    {
        $prefix = strtolower(self::SET_METHOD_PREFIX);
        if (!str_starts_with($methodLc, $prefix)) {
            return null;
        }

        return substr($methodLc, strlen($prefix));
    }

    public static function propertyNameFromGetHookMethod(string $methodLc): ?string
    {
        $prefix = strtolower(self::GET_METHOD_PREFIX);
        if (!str_starts_with($methodLc, $prefix)) {
            return null;
        }

        return substr($methodLc, strlen($prefix));
    }

    private static function lineAtOffset(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, max(0, $offset)), "\n") + 1;
    }

    /**
     * php-src: Zend/zend_compile.c — asymmetric `(set)` on the property decl inside a hook block
     * requires a set hook (`get; private set;`, `set =>`, …); get-only blocks are parse errors (#12203).
     */
    private function rejectAsymmetricDeclSetWithoutSetHook(
        string $declHead,
        string $hookSource,
        string $lcClass,
        string $prop,
        string $filename,
        string $fullCode,
        int $declOffsetInFile
    ): void {
        if (!$this->declHeadHasAsymmetricSetVisibility($declHead)) {
            return;
        }
        $propMeta = $this->registry[$lcClass][$prop] ?? [];
        $hasSetHook = isset($propMeta['set'])
            || !empty($propMeta['requiresSet'])
            || $this->isImplicitAsymmetricBackingHookSource($hookSource);
        if ($hasSetHook) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $declOffsetInFile),
            self::ASYMMETRIC_DECL_SET_REQUIRES_SET_HOOK_MESSAGE
        );
    }

    private function declHeadHasAsymmetricSetVisibility(string $declHead): bool
    {
        return (bool) preg_match('/\b(public|protected|private)\s*\(\s*set\s*\)/i', $declHead);
    }

    /**
     * First property-hook block for reference-profile rejection (#12574).
     *
     * @return array{0: int, 1: string}|null [1-based line, Zend parse error message]
     */
    public function locateFirstPropertyHookViolation(string $code): ?array
    {
        $offset = 0;
        $len = strlen($code);
        while ($offset < $len) {
            $decl = $this->findNextDeclarable($code, $offset);
            if (null === $decl) {
                break;
            }
            [$declPos] = $decl;
            $braceOpen = strpos($code, '{', $declPos);
            if (false === $braceOpen) {
                break;
            }
            $span = $this->matchingBraceSpan($code, $braceOpen);
            if (null === $span) {
                break;
            }
            [$bodyStart, $bodyEnd] = $span;
            $body = substr($code, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $hookDecl = $this->findNextPropertyHookDecl($body, 0);
            if (null !== $hookDecl) {
                [$prop, $varStart, $hookOpen] = $hookDecl;
                $bodyBase = $bodyStart + 1;
                $afterVar = $varStart + 1 + strlen($prop);
                $segment = substr($body, $afterVar, $hookOpen - $afterVar);
                if ((bool) preg_match('/=\s*\S/', $segment)) {
                    $hookSpan = $this->matchingBraceSpan($body, $hookOpen);
                    if (null !== $hookSpan) {
                        $hookInner = substr($body, $hookOpen + 1, $hookSpan[1] - $hookOpen - 1);
                        $arrowPos = $this->findHookBlockFatArrow($hookInner);
                        if (null !== $arrowPos) {
                            $absArrow = $bodyBase + $hookOpen + 1 + $arrowPos;

                            return [self::lineAtOffset($code, $absArrow), PropertyHookRejector::UNEXPECTED_ARROW_MESSAGE];
                        }
                    }
                }

                return [self::lineAtOffset($code, $bodyBase + $hookOpen), PropertyHookRejector::UNEXPECTED_BRACE_MESSAGE];
            }
            $offset = $bodyEnd + 1;
        }

        return null;
    }

    private function findHookBlockFatArrow(string $hookInner): ?int
    {
        if (!preg_match('/\b(?:get|set|unset)\s*=>\s*/', $hookInner, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $posInMatch = strpos($m[0][0], '=>');
        if (false === $posInMatch) {
            return null;
        }

        return $m[0][1] + $posInMatch;
    }
}
