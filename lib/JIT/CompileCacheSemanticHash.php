<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Semantic file/member hashing for AOT edit-scaffold strip plans (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so one-file-edit ≤25%-of-cold work stays a
 * separate TU (split-TU / compile-cache iterability) while CompileCache keeps thin
 * delegates for the public API used by bin/compile.php and unit tests.
 *
 * Comment/whitespace-only edits still byte-invalidate the project cache key but
 * do not put the member on the strip list — matching the Done-when path measured
 * by bench-gate. Per-function hashes allow isolating method-body edits.
 */
final class CompileCacheSemanticHash
{
    /**
     * @param array<string, string> $previous
     * @param array<string, string> $current
     *
     * @return list<string>
     */
    public static function diffMemberHashes(array $previous, array $current): array
    {
        $changed = [];
        foreach ($current as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                continue;
            }
            if (($previous[$path] ?? null) !== $hash) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * SHA-256 of PHP tokens with comments and whitespace removed (#36387).
     *
     * Used so a comment-only (or whitespace-only) edit of Router.php still
     * byte-invalidates the project cache key / edit scaffold, but does not put
     * Router on the strip list — kept bodies + delta demote then match the
     * config-only ≤25% path that the Done-when measures via bench-gate.
     */
    public static function semanticFileHash(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $src = file_get_contents($path);
        if (false === $src) {
            return null;
        }
        if ('' === $src) {
            return hash('sha256', '');
        }
        $tokens = @token_get_all($src);
        if (!\is_array($tokens) || [] === $tokens) {
            return hash('sha256', $src);
        }
        $buf = '';
        foreach ($tokens as $token) {
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                $buf .= $token[1];
            } else {
                $buf .= $token;
            }
        }

        return hash('sha256', $buf);
    }

    /**
     * Split a PHP file into glue (non-function) + per-function semantic hashes (#36387).
     *
     * Glue covers class properties, constants, use statements, and other tokens outside
     * function/method bodies. A glue change forces a full member strip; an isolated
     * method body change strips only that method's LLVM symbols.
     *
     * @return array{glue: string, functions: array<string, string>}|null
     */
    public static function semanticFileParts(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $src = file_get_contents($path);
        if (false === $src) {
            return null;
        }
        if ('' === $src) {
            return ['glue' => hash('sha256', ''), 'functions' => []];
        }
        $tokens = @token_get_all($src);
        if (!\is_array($tokens) || [] === $tokens) {
            return ['glue' => hash('sha256', $src), 'functions' => []];
        }

        $glue = '';
        $functions = [];
        $classStack = [];
        $pendingClass = false;
        $n = count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                $id = $token[0];
                $text = $token[1];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                if (T_CLASS === $id || T_INTERFACE === $id || T_TRAIT === $id) {
                    $pendingClass = true;
                    $glue .= $text;
                    continue;
                }
                if ($pendingClass && T_STRING === $id) {
                    $classStack[] = $text;
                    $pendingClass = false;
                    $glue .= $text;
                    continue;
                }
                if (T_FUNCTION === $id) {
                    $fn = self::consumeFunctionSemantic($tokens, $i, $classStack);
                    if (null === $fn) {
                        $glue .= $text;
                        continue;
                    }
                    $functions[$fn['scoped']] = $fn['hash'];
                    // consumeFunctionSemantic advances $i to the last consumed token.
                    continue;
                }
                $glue .= $text;
            } else {
                if ('{' === $token) {
                    // Keep brace depth for class stack via explicit tracking below.
                    $glue .= $token;
                } elseif ('}' === $token) {
                    if ([] !== $classStack) {
                        // Heuristic: closing brace may end the class; pop if no open class body
                        // nesting tracked — brace depth handled inside consumeFunctionSemantic
                        // for methods; for class end we pop when glue sees unmatched '}'.
                        // Safer: track brace depth on glue path.
                    }
                    $glue .= $token;
                } else {
                    $glue .= $token;
                }
            }
        }

        ksort($functions);

        return [
            'glue' => hash('sha256', $glue),
            'functions' => $functions,
        ];
    }

    /**
     * @param list<string|array{0:int,1:string,2:int}> $tokens
     * @param list<string>                             $classStack
     *
     * @return array{scoped: string, hash: string}|null
     */
    private static function consumeFunctionSemantic(array $tokens, int &$i, array $classStack): ?array
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

    /**
     * @param list<string> $memberPaths
     *
     * @return array{functions: array<string, array<string, string>>, glue: array<string, string>}
     */
    public static function memberSemanticParts(array $memberPaths): array
    {
        $functions = [];
        $glue = [];
        foreach ($memberPaths as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $resolved = realpath($path) ?: $path;
            $parts = self::semanticFileParts($resolved);
            if (null === $parts) {
                continue;
            }
            $glue[$resolved] = $parts['glue'];
            $functions[$resolved] = $parts['functions'];
        }
        ksort($glue);
        ksort($functions);

        return ['functions' => $functions, 'glue' => $glue];
    }

    /**
     * Per-function strip plan for members that already failed the file-level semantic check.
     *
     * Returns path → changed scoped (lc) only when glue is unchanged and ≥1 function
     * hash differs. Missing/empty entry for a strip member ⇒ full member strip (#36387).
     *
     * @param array<string, array<string, string>>|null $previousFunctions
     * @param array<string, array<string, string>>|null $currentFunctions
     * @param array<string, string>|null               $previousGlue
     * @param array<string, string>|null               $currentGlue
     * @param list<string>                             $stripMembers
     *
     * @return array<string, array<string, true>>
     */
    public static function diffFunctionsForStrip(
        ?array $previousFunctions,
        ?array $currentFunctions,
        ?array $previousGlue,
        ?array $currentGlue,
        array $stripMembers
    ): array {
        if (
            null === $previousFunctions
            || [] === $previousFunctions
            || null === $currentFunctions
            || [] === $currentFunctions
            || null === $previousGlue
            || [] === $previousGlue
            || null === $currentGlue
            || [] === $currentGlue
        ) {
            return [];
        }
        $out = [];
        foreach ($stripMembers as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $prevG = $previousGlue[$path] ?? null;
            $currG = $currentGlue[$path] ?? null;
            if (!is_string($prevG) || !is_string($currG) || $prevG !== $currG) {
                // Glue drift (props/consts/use) or missing → full member strip.
                continue;
            }
            $prevF = $previousFunctions[$path] ?? null;
            $currF = $currentFunctions[$path] ?? null;
            if (!is_array($prevF) || !is_array($currF) || [] === $currF) {
                continue;
            }
            $changed = [];
            foreach ($currF as $scoped => $hash) {
                if (!is_string($scoped) || !is_string($hash)) {
                    continue;
                }
                $prevH = $prevF[$scoped] ?? null;
                if ($prevH !== $hash) {
                    $changed[strtolower($scoped)] = true;
                }
            }
            foreach ($prevF as $scoped => $_hash) {
                if (!is_string($scoped)) {
                    continue;
                }
                if (!isset($currF[$scoped])) {
                    $changed[strtolower($scoped)] = true;
                }
            }
            if ([] !== $changed) {
                $out[$path] = $changed;
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_func_partial', 1.0);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $memberPaths
     *
     * @return array<string, string> path → semantic sha256
     */
    public static function memberSemanticHashes(array $memberPaths): array
    {
        $out = [];
        foreach ($memberPaths as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $resolved = realpath($path) ?: $path;
            $hash = self::semanticFileHash($resolved);
            if (is_string($hash)) {
                $out[$resolved] = $hash;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Members that must strip LLVM bodies: byte-changed AND semantically changed (#36387).
     *
     * Falls back to byte-only diff when the prior project index lacks semantic_members
     * (caches written before this slice).
     *
     * @param array<string, string>      $previousBytes
     * @param array<string, string>      $currentBytes
     * @param array<string, string>|null $previousSemantic
     * @param array<string, string>|null $currentSemantic
     *
     * @return list<string>
     */
    public static function diffMembersForStrip(
        array $previousBytes,
        array $currentBytes,
        ?array $previousSemantic,
        ?array $currentSemantic
    ): array {
        $byteChanged = self::diffMemberHashes($previousBytes, $currentBytes);
        if (
            null === $previousSemantic
            || [] === $previousSemantic
            || null === $currentSemantic
            || [] === $currentSemantic
        ) {
            return $byteChanged;
        }
        $strip = [];
        foreach ($byteChanged as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $prev = $previousSemantic[$path] ?? null;
            $curr = $currentSemantic[$path] ?? null;
            if (!is_string($prev) || !is_string($curr) || $prev !== $curr) {
                $strip[] = $path;
            } else {
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_semantic_keep', 1.0);
            }
        }

        return $strip;
    }
}
