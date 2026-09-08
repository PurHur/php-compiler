<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Symbol-name / prior-object probes for AOT partial-edit demote (#36387 / #36199).
 *
 * Extracted from {@see CompileCachePartialEmitLlvm} so shared-runtime demote
 * candidacy, LLVM function name lookup, and `nm` defined-symbol probes stay a
 * separate TU from rename+declaration demote (split-TU / size-budget ratchet).
 * Called from the demote hub and from {@see CompileCachePartialEmitLlvm}.
 *
 * No new C ABI. php-src analogy: Zend opcache inspects which symbols belong to a
 * cached unit image before invalidation (Zend/zend_file_cache.c shape); there is
 * no partial-object demote.
 */
final class CompileCachePartialEmitSymbolProbe
{
    /**
     * Runtime / NestedJIT symbols safe to take from prior aot.o on partial edit (#36387).
     */
    public static function isSharedRuntimeDemoteCandidate(string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        // NestedJIT helpers already linked into prior aot.o — demote via safe rename+delete.
        if (str_starts_with($name, 'PHPCompiler_')) {
            return true;
        }

        return str_starts_with($name, '__value__')
            || str_starts_with($name, '__string__')
            || str_starts_with($name, '__hashtable__')
            || str_starts_with($name, '__ref__')
            || str_starts_with($name, '__object__')
            || str_starts_with($name, 'phpc_')
            || str_starts_with($name, '__compiler_')
            || str_starts_with($name, '__phpc_')
            || str_starts_with($name, '__superglobals__')
            || str_starts_with($name, 'internal_');
    }

    public static function llvmFunctionName(Context $context, object $fn): string
    {
        if (!isset($fn->value)) {
            return '';
        }
        try {
            $raw = $context->llvm->lib->LLVMGetValueName($fn->value);
        } catch (\Throwable $e) {
            return '';
        }
        if (null === $raw) {
            return '';
        }
        if (is_object($raw) && method_exists($raw, 'toString')) {
            return (string) $raw->toString();
        }

        return is_string($raw) ? $raw : '';
    }

    /**
     * Defined ELF symbol names in an object file (nm -g --defined-only) (#36387).
     *
     * @return array<string, true>
     */
    public static function objectDefinedSymbols(string $objectPath): array
    {
        if (!is_file($objectPath) || filesize($objectPath) < 1) {
            return [];
        }
        $out = [];
        $rc = 1;
        exec('nm -g --defined-only '.escapeshellarg($objectPath).' 2>/dev/null', $out, $rc);
        if (0 !== $rc) {
            return [];
        }
        $defs = [];
        foreach ($out as $line) {
            $parts = preg_split('/\s+/', trim((string) $line));
            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }
            $name = $parts[count($parts) - 1];
            if (is_string($name) && '' !== $name && !str_starts_with($name, '.')) {
                $defs[$name] = true;
            }
        }

        return $defs;
    }
}
