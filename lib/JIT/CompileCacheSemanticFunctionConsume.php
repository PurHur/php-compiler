<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Token-walk consume of one function/method body for AOT semantic file-parts (#36387 / #36199).
 *
 * Extracted from {@see CompileCacheSemanticFileParts} so the file-level glue walk stays
 * separate from per-function name/body hashing (split-TU / compile-cache iterability).
 * Called only from SemanticFileParts when the walk hits T_FUNCTION.
 *
 * Move-only — no new C ABI. php-src analogy: Zend/zend_accelerator_hash.c (per-function
 * checksum shape vs whole-script invalidation).
 */
final class CompileCacheSemanticFunctionConsume
{
    /**
     * Advance past one function/method starting at T_FUNCTION; hash the body (or abstract sig).
     *
     * @param list<string|array{0:int,1:string,2:int}> $tokens
     * @param list<string>                             $classStack
     *
     * @return array{scoped: string, hash: string}|null
     */
    public static function consume(array $tokens, int &$i, array $classStack): ?array
    {
        $n = count($tokens);
        // Skip attributes / modifiers already consumed; $tokens[$i] is T_FUNCTION.
        ++$i;
        $name = '';
        for (; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                if (T_STRING === $id || (defined('T_NAME_QUALIFIED') && T_NAME_QUALIFIED === $id) || (defined('T_NAME_FULLY_QUALIFIED') && T_NAME_FULLY_QUALIFIED === $id)) {
                    $name = $token[1];
                    ++$i;
                    break;
                }
                // Anonymous function / arrow — treat as glue by bailing.
                if ('(' === $token[1] || T_FN === $id) {
                    --$i;

                    return null;
                }
            } else {
                if ('(' === $token) {
                    --$i;

                    return null;
                }
                if ('&' === $token) {
                    continue;
                }
            }
        }
        if ('' === $name) {
            return null;
        }
        // Skip parameter list to body '{' or ';' (abstract).
        $paren = 0;
        $sawParen = false;
        $bodyStart = -1;
        for (; $i < $n; ++$i) {
            $token = $tokens[$i];
            $ch = \is_array($token) ? $token[1] : $token;
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
            }
            if ('(' === $ch) {
                ++$paren;
                $sawParen = true;
                continue;
            }
            if (')' === $ch) {
                --$paren;
                continue;
            }
            if ($sawParen && 0 === $paren) {
                if ('{' === $ch) {
                    $bodyStart = $i;
                    break;
                }
                if (';' === $ch) {
                    // Abstract / interface method — hash signature only.
                    $scoped = [] !== $classStack
                        ? $classStack[count($classStack) - 1].'::'.$name
                        : $name;
                    return ['scoped' => $scoped, 'hash' => hash('sha256', 'abstract:'.$name)];
                }
            }
        }
        if ($bodyStart < 0) {
            return null;
        }
        $body = '';
        $brace = 0;
        for (; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                $ch = $token[1];
            } else {
                $ch = $token;
            }
            if ('{' === $ch) {
                ++$brace;
                $body .= $ch;
                continue;
            }
            if ('}' === $ch) {
                --$brace;
                $body .= $ch;
                if (0 === $brace) {
                    break;
                }
                continue;
            }
            $body .= $ch;
        }
        $scoped = [] !== $classStack
            ? $classStack[count($classStack) - 1].'::'.$name
            : $name;

        return ['scoped' => $scoped, 'hash' => hash('sha256', $body)];
    }
}
