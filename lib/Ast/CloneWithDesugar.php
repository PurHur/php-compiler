<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Desugar PHP 8.3+ `clone $obj with { prop: $value, ... }` and PHP 8.4+
 * `clone($obj, ['prop' => $value, ...])` / `clone $obj with ['prop', ...]`
 * before nikic/php-parser (#4513, #9743, #9995).
 *
 * Rewrites to an IIFE that clones then assigns properties — matches Zend/zend_compile.c lowering.
 * php-src: Zend/zend_language_parser.y clone_expr / with clause; zend_clones.c.
 */
final class CloneWithDesugar
{
    /** Marker for clone-with property list entries without an explicit value (#10310). */
    public const REINIT_SENTINEL = '__phpc_reinit__';

    public static function desugar(string $code): string
    {
        if (!preg_match('/\bclone\b/i', $code)) {
            return $code;
        }

        for ($guard = 0; $guard < 256; ++$guard) {
            $tokens = token_get_all($code);
            $span = self::findCloneCallSpan($code, $tokens);
            if (null === $span) {
                break;
            }

            $replacement = self::rewriteCloneCallSpan($span);
            $code = substr($code, 0, $span['start'])
                .$replacement
                .substr($code, $span['end']);
        }

        if (!preg_match('/\bwith\b/i', $code)) {
            return $code;
        }

        for ($guard = 0; $guard < 256; ++$guard) {
            $tokens = token_get_all($code);
            $span = self::findCloneWithSpan($code, $tokens);
            if (null === $span) {
                break;
            }

            $assignments = isset($span['arrayText'])
                ? self::parseCloneWithArrayAssignments($span['arrayText'])
                : self::parsePropertyAssignments($span['blockText']);
            if ([] === $assignments) {
                break;
            }

            $replacement = self::buildCloneWithIife($span['exprText'], $assignments);
            $code = substr($code, 0, $span['start'])
                .$replacement
                .substr($code, $span['end']);
        }

        return $code;
    }

    /**
     * PHP 8.4+ `clone($obj, ['prop' => $val])` / `clone($obj, ['prop'])` (#9743).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, exprText: string, arrayText: string}|null
     */
    private static function findCloneCallSpan(string $code, array $tokens): ?array
    {
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $cloneIdx = $i;
            if (\is_array($tokens[$i]) && \T_NS_SEPARATOR === $tokens[$i][0]) {
                $next = $i + 1;
                if (!isset($tokens[$next]) || !\is_array($tokens[$next]) || \T_CLONE !== $tokens[$next][0]) {
                    continue;
                }
                $cloneIdx = $next;
                $i = $next;
            } elseif (!\is_array($tokens[$i]) || \T_CLONE !== $tokens[$i][0]) {
                continue;
            }

            $openIdx = $cloneIdx + 1;
            self::skipForwardIgnorable($tokens, $openIdx);
            if ($openIdx >= $c || '(' !== $tokens[$openIdx]) {
                continue;
            }

            $exprStart = $openIdx + 1;
            self::skipForwardIgnorable($tokens, $exprStart);
            if ($exprStart >= $c) {
                continue;
            }

            $exprEnd = self::scanCloneCallFirstArgEnd($tokens, $exprStart);
            if (null === $exprEnd) {
                continue;
            }

            $commaIdx = $exprEnd + 1;
            self::skipForwardIgnorable($tokens, $commaIdx);
            if ($commaIdx >= $c || ',' !== $tokens[$commaIdx]) {
                continue;
            }

            $arrayStart = $commaIdx + 1;
            self::skipForwardIgnorable($tokens, $arrayStart);
            if ($arrayStart >= $c) {
                continue;
            }

            $arrayEnd = self::scanCloneCallSecondArgEnd($tokens, $arrayStart);
            if (null === $arrayEnd) {
                continue;
            }

            $closeIdx = $arrayEnd + 1;
            self::skipForwardIgnorable($tokens, $closeIdx);
            if ($closeIdx >= $c || ')' !== $tokens[$closeIdx]) {
                continue;
            }

            $spanStartIdx = $cloneIdx;
            if ($cloneIdx > 0 && \is_array($tokens[$cloneIdx - 1]) && \T_NS_SEPARATOR === $tokens[$cloneIdx - 1][0]) {
                $spanStartIdx = $cloneIdx - 1;
            }

            $start = self::tokenByteOffset($tokens, $spanStartIdx);
            $end = self::tokenByteEnd($tokens, $closeIdx);
            $exprTextStart = self::tokenByteOffset($tokens, $exprStart);
            $exprTextEnd = self::tokenByteEnd($tokens, $exprEnd);
            $arrayTextStart = self::tokenByteOffset($tokens, $arrayStart);
            $arrayTextEnd = self::tokenByteEnd($tokens, $arrayEnd);
            if (null === $start || null === $end || null === $exprTextStart || null === $exprTextEnd
                || null === $arrayTextStart || null === $arrayTextEnd) {
                continue;
            }

            return [
                'start' => $start,
                'end' => $end,
                'exprText' => trim(substr($code, $exprTextStart, $exprTextEnd - $exprTextStart)),
                'arrayText' => trim(substr($code, $arrayTextStart, $arrayTextEnd - $arrayTextStart)),
            ];
        }

        return null;
    }

    /**
     * @param array{start: int, end: int, exprText: string, arrayText: string} $span
     */
    private static function rewriteCloneCallSpan(array $span): string
    {
        $assignments = self::parseCloneWithArrayAssignments($span['arrayText']);
        if ([] === $assignments) {
            return 'clone '.$span['exprText'];
        }

        return self::buildCloneWithIife($span['exprText'], $assignments);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function parseCloneWithArrayAssignments(string $arrayText): array
    {
        $arrayText = trim($arrayText);
        if ('' === $arrayText) {
            return [];
        }

        $tokens = token_get_all('<?php '.$arrayText.';');
        if (!\is_array($tokens)) {
            return [];
        }

        $openIdx = null;
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (\is_string($tokens[$i]) && '[' === $tokens[$i]) {
                $openIdx = $i;
                break;
            }
            if (\is_array($tokens[$i]) && \T_ARRAY === $tokens[$i][0]) {
                $openIdx = $i + 1;
                self::skipForwardIgnorable($tokens, $openIdx);
                if ($openIdx < $c && \is_string($tokens[$openIdx]) && '[' === $tokens[$openIdx]) {
                    break;
                }

                return self::parseCloneWithArrayFromVariable($arrayText);
            }
        }

        if (null === $openIdx) {
            return self::parseCloneWithArrayFromVariable($arrayText);
        }

        $closeIdx = self::skipBalancedForward($tokens, $openIdx, '[', ']');
        if (null === $closeIdx) {
            return [];
        }

        $innerStart = $openIdx + 1;
        $innerEnd = $closeIdx - 1;
        if ($innerStart > $innerEnd) {
            return [];
        }

        $snippet = '';
        foreach ($tokens as $token) {
            $snippet .= self::tokenText($token);
        }
        $innerStartByte = self::tokenByteOffset($tokens, $innerStart);
        $innerEndByte = self::tokenByteEnd($tokens, $innerEnd);
        if (null === $innerStartByte || null === $innerEndByte) {
            return [];
        }
        $inner = trim(substr($snippet, $innerStartByte, $innerEndByte - $innerStartByte + 1));
        if ('' === $inner) {
            return [];
        }

        return self::parseCloneWithArrayInner($inner);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function parseCloneWithArrayFromVariable(string $arrayText): array
    {
        $var = trim($arrayText);
        if ('' === $var) {
            return [];
        }

        return [['__phpc_dynamic__', $var]];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function parseCloneWithArrayInner(string $inner): array
    {
        $tokens = token_get_all('<?php '.$inner.';');
        if (!\is_array($tokens)) {
            return [];
        }

        $assignments = [];
        $i = 0;
        $c = \count($tokens);
        while ($i < $c) {
            self::skipForwardIgnorable($tokens, $i);
            if ($i >= $c) {
                break;
            }

            $keyToken = $tokens[$i];
            $key = null;
            $valueStart = null;

            if (\is_array($keyToken) && \in_array($keyToken[0], [\T_CONSTANT_ENCAPSED_STRING, \T_STRING], true)) {
                $key = self::unquoteString($keyToken[1]);
                ++$i;
                self::skipForwardIgnorable($tokens, $i);
                if ($i < $c && self::isDoubleArrow($tokens, $i)) {
                    ++$i;
                    $valueStart = $i;
                    self::skipForwardIgnorable($tokens, $valueStart);
                } else {
                    $assignments[] = [$key, CloneWithDesugar::REINIT_SENTINEL];

                    if ($i < $c && \is_string($tokens[$i]) && ',' === $tokens[$i]) {
                        ++$i;
                    }
                    continue;
                }
            } elseif (\is_array($keyToken) && \T_LNUMBER === $keyToken[0]) {
                ++$i;
                self::skipForwardIgnorable($tokens, $i);
                if ($i >= $c || !self::isDoubleArrow($tokens, $i)) {
                    break;
                }
                ++$i;
                $valueStart = $i;
                self::skipForwardIgnorable($tokens, $valueStart);
            } else {
                break;
            }

            if (null === $key || null === $valueStart || $valueStart >= $c) {
                break;
            }

            $valueEnd = self::scanPropertyValueEnd($tokens, $valueStart);
            if (null === $valueEnd) {
                break;
            }

            $snippet = '';
            foreach ($tokens as $token) {
                $snippet .= self::tokenText($token);
            }
            $valueStartByte = self::tokenByteOffset($tokens, $valueStart);
            $valueEndByte = self::tokenByteEnd($tokens, $valueEnd);
            if (null === $valueStartByte || null === $valueEndByte) {
                break;
            }
            $valueText = trim(substr($snippet, $valueStartByte, $valueEndByte - $valueStartByte));
            $assignments[] = [$key, $valueText];

            $i = $valueEnd + 1;
            self::skipForwardIgnorable($tokens, $i);
            if ($i < $c && \is_string($tokens[$i]) && ',' === $tokens[$i]) {
                ++$i;
                continue;
            }
            break;
        }

        return $assignments;
    }

    private static function unquoteString(string $literal): string
    {
        if (\strlen($literal) >= 2 && ($literal[0] === "'" || $literal[0] === '"')) {
            return stripcslashes(substr($literal, 1, -1));
        }

        return $literal;
    }

    /**
     * @param list<array{0: string, 1: string}> $assignments
     */
    private static function buildCloneWithIife(string $exprText, array $assignments): string
    {
        $body = '$__phpc_r = clone $__phpc_o;';
        $propArgs = [];
        foreach ($assignments as [$name, $_value]) {
            if ('__phpc_dynamic__' === $name) {
                continue;
            }
            $propArgs[] = var_export($name, true);
        }
        if ([] !== $propArgs) {
            $body .= 'phpc_clone_with_begin($__phpc_r, '.implode(', ', $propArgs).');';
        }
        foreach ($assignments as [$name, $value]) {
            if ('__phpc_dynamic__' === $name) {
                $body .= 'foreach ('.$value.' as $__phpc_k => $__phpc_v) { '
                    .'if (is_int($__phpc_k)) { $__phpc_k = (string) $__phpc_k; } '
                    .'$__phpc_r->{$__phpc_k} = $__phpc_v; }';
                continue;
            }
            if (self::REINIT_SENTINEL === $value) {
                $body .= 'phpc_clone_with_reinit($__phpc_r, '.var_export($name, true).');';
                continue;
            }
            $body .= '$__phpc_r->'.$name.' = '.$value.';';
        }
        if ([] !== $propArgs) {
            $body .= 'phpc_clone_with_end($__phpc_r);';
        }
        $body .= 'return $__phpc_r;';

        return '(function ($__phpc_o) { '.$body.' })('.$exprText.')';
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanCloneCallFirstArgEnd(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        $depthParen = 0;
        $depthBracket = 0;
        $depthBrace = 0;
        $endIdx = null;

        for ($i = $startIdx; $i < \count($tokens); ++$i) {
            $token = $tokens[$i];
            if (self::isIgnorable($token)) {
                continue;
            }

            if (\is_string($token)) {
                if ('(' === $token) {
                    ++$depthParen;
                } elseif (')' === $token && 0 === $depthParen && 0 === $depthBracket && 0 === $depthBrace) {
                    return null === $endIdx ? null : $endIdx;
                } elseif (',' === $token && 0 === $depthParen && 0 === $depthBracket && 0 === $depthBrace) {
                    return null === $endIdx ? null : $endIdx;
                } elseif ('[' === $token) {
                    ++$depthBracket;
                } elseif (']' === $token) {
                    --$depthBracket;
                } elseif ('{' === $token) {
                    ++$depthBrace;
                } elseif ('}' === $token) {
                    --$depthBrace;
                }
            }

            if (0 === $depthParen && 0 === $depthBracket && 0 === $depthBrace) {
                $endIdx = $i;
            }
            $pos = $i;
        }

        return $endIdx;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanCloneCallSecondArgEnd(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        self::skipForwardIgnorable($tokens, $pos);
        if ($pos >= \count($tokens)) {
            return null;
        }

        if (\is_string($tokens[$pos]) && '[' === $tokens[$pos]) {
            $closeBracketIdx = self::skipBalancedForward($tokens, $pos, '[', ']');
            if (null === $closeBracketIdx) {
                return null;
            }

            return $closeBracketIdx;
        }

        $endIdx = self::scanPrimaryForward($tokens, $pos);
        if (null === $endIdx) {
            return null;
        }
        $pos = $endIdx + 1;

        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                return $endIdx;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && ',' === $token) {
                return $endIdx;
            }
            if (\is_string($token) && ')' === $token) {
                return $endIdx;
            }
            if (\is_string($token) && '[' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '[', ']');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_string($token) && '(' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '(', ')');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= \count($tokens)) {
                    return null;
                }
                $endIdx = $pos;
                ++$pos;
                continue;
            }

            break;
        }

        return $endIdx;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, exprText: string, blockText?: string, arrayText?: string}|null
     */
    private static function findCloneWithSpan(string $code, array $tokens): ?array
    {
        $parenSpan = self::findParenCloneWithSpan($code, $tokens);
        if (null !== $parenSpan) {
            return $parenSpan;
        }

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || \T_CLONE !== $token[0]) {
                continue;
            }

            $exprStart = $i + 1;
            self::skipForwardIgnorable($tokens, $exprStart);
            if ($exprStart >= $c) {
                continue;
            }

            $exprEnd = self::scanCloneOperandEnd($tokens, $exprStart);
            if (null === $exprEnd) {
                continue;
            }

            $withIdx = $exprEnd + 1;
            self::skipForwardIgnorable($tokens, $withIdx);
            if (!self::isWithKeyword($tokens, $withIdx)) {
                continue;
            }

            $listIdx = $withIdx + 1;
            self::skipForwardIgnorable($tokens, $listIdx);
            if ($listIdx >= $c) {
                continue;
            }

            $listEndIdx = null;
            $arrayText = null;
            $blockText = null;

            if ('[' === $tokens[$listIdx]) {
                $listEndIdx = self::skipBalancedForward($tokens, $listIdx, '[', ']');
                if (null === $listEndIdx) {
                    continue;
                }
                $arrayTextStart = self::tokenByteOffset($tokens, $listIdx);
                $arrayTextEnd = self::tokenByteEnd($tokens, $listEndIdx);
                if (null === $arrayTextStart || null === $arrayTextEnd) {
                    continue;
                }
                $arrayText = trim(substr($code, $arrayTextStart, $arrayTextEnd - $arrayTextStart));
            } elseif ('{' === $tokens[$listIdx]) {
                $listEndIdx = self::skipBalancedBraces($tokens, $listIdx);
                if (null === $listEndIdx) {
                    continue;
                }
                $blockOpen = self::tokenByteEnd($tokens, $listIdx);
                $blockClose = self::tokenByteOffset($tokens, $listEndIdx);
                if (null === $blockOpen || null === $blockClose) {
                    continue;
                }
                $blockText = trim(substr($code, $blockOpen, $blockClose - $blockOpen));
            } else {
                continue;
            }

            $start = self::tokenByteOffset($tokens, $i);
            $end = self::tokenByteEnd($tokens, $listEndIdx);
            $exprTextStart = self::tokenByteOffset($tokens, $exprStart);
            $exprTextEnd = self::tokenByteEnd($tokens, $exprEnd);
            if (null === $start || null === $end || null === $exprTextStart || null === $exprTextEnd) {
                continue;
            }

            $span = [
                'start' => $start,
                'end' => $end,
                'exprText' => trim(substr($code, $exprTextStart, $exprTextEnd - $exprTextStart)),
            ];
            if (null !== $arrayText) {
                $span['arrayText'] = $arrayText;
            } else {
                $span['blockText'] = $blockText;
            }

            return $span;
        }

        return null;
    }

    /**
     * PHP 8.4+ `(clone $obj) with ['prop' => $val]` — parenthesized clone operand (#10496).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, exprText: string, blockText?: string, arrayText?: string}|null
     */
    private static function findParenCloneWithSpan(string $code, array $tokens): ?array
    {
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (!\is_string($tokens[$i]) || '(' !== $tokens[$i]) {
                continue;
            }

            $cloneIdx = $i + 1;
            self::skipForwardIgnorable($tokens, $cloneIdx);
            if ($cloneIdx >= $c || !\is_array($tokens[$cloneIdx]) || \T_CLONE !== $tokens[$cloneIdx][0]) {
                continue;
            }

            $exprStart = $cloneIdx + 1;
            self::skipForwardIgnorable($tokens, $exprStart);
            if ($exprStart >= $c) {
                continue;
            }

            $exprEnd = self::scanCloneOperandEnd($tokens, $exprStart);
            if (null === $exprEnd) {
                continue;
            }

            $closeIdx = $exprEnd + 1;
            self::skipForwardIgnorable($tokens, $closeIdx);
            if ($closeIdx >= $c || ')' !== $tokens[$closeIdx]) {
                continue;
            }

            $withIdx = $closeIdx + 1;
            self::skipForwardIgnorable($tokens, $withIdx);
            if (!self::isWithKeyword($tokens, $withIdx)) {
                continue;
            }

            $listIdx = $withIdx + 1;
            self::skipForwardIgnorable($tokens, $listIdx);
            if ($listIdx >= $c) {
                continue;
            }

            $listEndIdx = null;
            $arrayText = null;
            $blockText = null;

            if ('[' === $tokens[$listIdx]) {
                $listEndIdx = self::skipBalancedForward($tokens, $listIdx, '[', ']');
                if (null === $listEndIdx) {
                    continue;
                }
                $arrayTextStart = self::tokenByteOffset($tokens, $listIdx);
                $arrayTextEnd = self::tokenByteEnd($tokens, $listEndIdx);
                if (null === $arrayTextStart || null === $arrayTextEnd) {
                    continue;
                }
                $arrayText = trim(substr($code, $arrayTextStart, $arrayTextEnd - $arrayTextStart));
            } elseif ('{' === $tokens[$listIdx]) {
                $listEndIdx = self::skipBalancedBraces($tokens, $listIdx);
                if (null === $listEndIdx) {
                    continue;
                }
                $blockOpen = self::tokenByteEnd($tokens, $listIdx);
                $blockClose = self::tokenByteOffset($tokens, $listEndIdx);
                if (null === $blockOpen || null === $blockClose) {
                    continue;
                }
                $blockText = trim(substr($code, $blockOpen, $blockClose - $blockOpen));
            } else {
                continue;
            }

            $start = self::tokenByteOffset($tokens, $i);
            $end = self::tokenByteEnd($tokens, $listEndIdx);
            $exprTextStart = self::tokenByteOffset($tokens, $exprStart);
            $exprTextEnd = self::tokenByteEnd($tokens, $exprEnd);
            if (null === $start || null === $end || null === $exprTextStart || null === $exprTextEnd) {
                continue;
            }

            $span = [
                'start' => $start,
                'end' => $end,
                'exprText' => trim(substr($code, $exprTextStart, $exprTextEnd - $exprTextStart)),
            ];
            if (null !== $arrayText) {
                $span['arrayText'] = $arrayText;
            } else {
                $span['blockText'] = $blockText;
            }

            return $span;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function parsePropertyAssignments(string $blockText): array
    {
        $blockText = trim($blockText);
        if ('' === $blockText) {
            return [];
        }

        $tokens = token_get_all('<?php '.$blockText.';');
        if (!\is_array($tokens)) {
            return [];
        }

        $assignments = [];
        $i = 0;
        $c = \count($tokens);
        while ($i < $c) {
            self::skipForwardIgnorable($tokens, $i);
            if ($i >= $c) {
                break;
            }

            $token = $tokens[$i];
            if (!\is_array($token) || \T_STRING !== $token[0]) {
                break;
            }
            $name = $token[1];
            ++$i;
            self::skipForwardIgnorable($tokens, $i);
            if ($i >= $c || ':' !== $tokens[$i]) {
                break;
            }
            ++$i;

            $valueStart = $i;
            self::skipForwardIgnorable($tokens, $valueStart);
            if ($valueStart >= $c) {
                break;
            }

            $valueEnd = self::scanPropertyValueEnd($tokens, $valueStart);
            if (null === $valueEnd) {
                break;
            }

            $valueStartByte = self::tokenByteOffset($tokens, $valueStart);
            $valueEndByte = self::tokenByteEnd($tokens, $valueEnd);
            if (null === $valueStartByte || null === $valueEndByte) {
                break;
            }
            $snippet = '';
            foreach ($tokens as $token) {
                $snippet .= self::tokenText($token);
            }
            $valueText = trim(substr($snippet, $valueStartByte, $valueEndByte - $valueStartByte));
            $assignments[] = [$name, $valueText];

            $i = $valueEnd + 1;
            self::skipForwardIgnorable($tokens, $i);
            if ($i < $c && ',' === $tokens[$i]) {
                ++$i;
                continue;
            }
            break;
        }

        return $assignments;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanPropertyValueEnd(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        $endIdx = self::scanPrimaryForward($tokens, $pos);
        if (null === $endIdx) {
            return null;
        }
        $pos = $endIdx + 1;

        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                return $endIdx;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && ',' === $token) {
                return $endIdx;
            }
            if (\is_string($token) && ';' === $token) {
                return $endIdx;
            }
            if (\is_string($token) && '[' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '[', ']');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_string($token) && '(' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '(', ')');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= \count($tokens)) {
                    return null;
                }
                $endIdx = $pos;
                ++$pos;
                continue;
            }

            break;
        }

        return $endIdx;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanCloneOperandEnd(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        $endIdx = self::scanPrimaryForward($tokens, $pos);
        if (null === $endIdx) {
            return null;
        }
        $pos = $endIdx + 1;

        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                return $endIdx;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && '[' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '[', ']');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_string($token) && '(' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '(', ')');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= \count($tokens)) {
                    return null;
                }
                $endIdx = $pos;
                ++$pos;
                continue;
            }

            break;
        }

        return $endIdx;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanPrimaryForward(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= \count($tokens)) {
            return null;
        }

        $token = $tokens[$pos];
        if (\is_string($token) && '(' === $token) {
            $next = self::skipBalancedForward($tokens, $pos, '(', ')');

            return null === $next ? null : $next - 1;
        }
        if (\is_array($token) && \in_array($token[0], [
            T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
            T_ARRAY, T_NEW, T_CLONE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true)) {
            if (\T_NEW === $token[0]) {
                return self::scanNewExpressionEnd($tokens, $pos);
            }

            return $pos;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanNewExpressionEnd(array $tokens, int $newIdx): ?int
    {
        $pos = $newIdx + 1;
        self::skipForwardIgnorable($tokens, $pos);
        if ($pos >= \count($tokens)) {
            return null;
        }

        if (!self::skipNewClassTarget($tokens, $pos)) {
            return null;
        }

        self::skipForwardIgnorable($tokens, $pos);
        if ($pos < \count($tokens) && '(' === $tokens[$pos]) {
            if (null === self::skipBalancedForward($tokens, $pos, '(', ')')) {
                return null;
            }
        }

        return $pos - 1;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipNewClassTarget(array $tokens, int &$pos): bool
    {
        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                return false;
            }

            $token = $tokens[$pos];
            if (\is_array($token) && \in_array($token[0], [
                T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR,
            ], true)) {
                ++$pos;
                continue;
            }

            break;
        }

        return true;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isWithKeyword(array $tokens, int $idx): bool
    {
        if (!isset($tokens[$idx])) {
            return false;
        }
        $token = $tokens[$idx];
        if (\is_array($token) && \defined('T_WITH') && \T_WITH === $token[0]) {
            return true;
        }

        return \is_array($token) && \T_STRING === $token[0] && 'with' === $token[1];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBalancedBraces(array $tokens, int $openIdx): ?int
    {
        return self::skipBalancedForward($tokens, $openIdx, '{', '}');
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBalancedForward(array $tokens, int $openIdx, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openIdx; $i < \count($tokens); ++$i) {
            $t = $tokens[$i];
            if (\is_string($t) && $open === $t) {
                ++$depth;
            } elseif (\is_string($t) && $close === $t) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            } elseif (\is_array($t) && \T_CURLY_OPEN === $t[0] && '(' === $open) {
                ++$depth;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isDoubleArrow(array $tokens, int $idx): bool
    {
        if (!isset($tokens[$idx])) {
            return false;
        }
        $token = $tokens[$idx];
        if (\is_array($token) && \T_DOUBLE_ARROW === $token[0]) {
            return true;
        }

        return \is_string($token) && '=>' === $token;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
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
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenByteEnd(array $tokens, int $idx): ?int
    {
        $start = self::tokenByteOffset($tokens, $idx);
        if (null === $start) {
            return null;
        }

        return $start + \strlen(self::tokenText($tokens[$idx]));
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipForwardIgnorable(array $tokens, int &$pos): void
    {
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
    }
}
