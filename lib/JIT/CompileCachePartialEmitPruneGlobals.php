<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Unused const-global prune after AOT partial-edit demote (#36387 / #36199).
 *
 * Extracted from {@see CompileCachePartialEmitLlvm} so dense named-global probe +
 * use-count delete stays a separate TU from rename+declaration demote and prior-
 * object nm probes (split-TU / size-budget ratchet). Called from
 * {@see CompileCachePartialEmitDemote} after bodies are demoted.
 *
 * No new C ABI. php-src analogy: Zend opcache drops dead literals when invalidating
 * a unit image (Zend/zend_file_cache.c shape); there is no partial-object demote.
 */
final class CompileCachePartialEmitPruneGlobals
{
    /**
     * After demoting unchanged bodies, drop unused user const globals so
     * sibling-member string/array consts do not inflate the delta `.o` (#36387).
     *
     * Dense named lookup only (no full-module global walk) — walking every
     * NestedJIT global dominates tiny edit scaffolds and erased the emit win.
     */
    public static function pruneUnusedGlobalsAfterDemote(Context $context): int
    {
        $prefixes = ['string_const_', 'array_const_', 'object_const_'];
        $suffixes = ['_main', ''];
        $pruned = 0;
        for ($pass = 0; $pass < 3; ++$pass) {
            $batch = [];
            $misses = 0;
            for ($i = 0; $i < 4096; ++$i) {
                $hit = false;
                foreach ($prefixes as $prefix) {
                    foreach ($suffixes as $suffix) {
                        $name = $prefix.$i.$suffix;
                        $g = null;
                        try {
                            $g = $context->module->getNamedGlobal($name);
                        } catch (\Throwable $e) {
                            $g = null;
                        }
                        if (!$g instanceof \PHPLLVM\Value) {
                            continue;
                        }
                        $hit = true;
                        if (self::llvmValueHasNoUses($context, $g)) {
                            $batch[] = $g;
                        }
                    }
                }
                if ($hit) {
                    $misses = 0;
                } elseif (++$misses >= 64) {
                    break;
                }
            }
            if ([] === $batch) {
                break;
            }
            foreach ($batch as $g) {
                if (!is_object($g) || !method_exists($g, 'delete')) {
                    continue;
                }
                try {
                    $g->delete();
                    ++$pruned;
                } catch (\Throwable $e) {
                }
            }
        }

        return $pruned;
    }

    public static function llvmValueHasNoUses(Context $context, object $value): bool
    {
        if (!isset($value->value)) {
            return false;
        }
        try {
            $use = $context->llvm->lib->LLVMGetFirstUse($value->value);
        } catch (\Throwable $e) {
            return false;
        }

        return null === $use;
    }
}
