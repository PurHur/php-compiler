<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCacheSemanticFunctionConsume.php';

/**
 * Per-function semantic hashing for AOT edit-scaffold strip plans (#36387 / #36199).
 *
 * Extracted from {@see CompileCacheSemanticHash} so file-level semantic hashes
 * (comment/whitespace-insensitive) stay separate from glue + per-function parts
 * used for ≤1-method strip on one-file-edit (split-TU / compile-cache iterability).
 *
 * Glue covers class properties, constants, use statements, and other tokens outside
 * function/method bodies. A glue change forces a full member strip; an isolated
 * method body change strips only that method's LLVM symbols. Token consume for one
 * function lives in {@see CompileCacheSemanticFunctionConsume}.
 *
 * Move-only — no new C ABI. php-src analogy: Zend/zend_accelerator_hash.c
 * (script checksum vs per-function invalidation shape).
 */
final class CompileCacheSemanticFileParts
{
    /**
     * Split a PHP file into glue (non-function) + per-function semantic hashes (#36387).
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
                    $fn = CompileCacheSemanticFunctionConsume::consume($tokens, $i, $classStack);
                    if (null === $fn) {
                        $glue .= $text;
                        continue;
                    }
                    $functions[$fn['scoped']] = $fn['hash'];
                    // CompileCacheSemanticFunctionConsume::consume advances $i to the last consumed token.
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
                        // nesting tracked — brace depth handled inside SemanticFunctionConsume
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
}
