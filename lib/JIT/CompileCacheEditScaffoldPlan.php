<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Edit-scaffold strip planning for AOT one-file-edit (#36387 / #36403).
 *
 * Extracted from {@see CompileCacheEditScaffold} so changed-member / kept-symbol
 * attribution stays a separate TU (split-TU / size-budget ratchet) while restore
 * stays on the scaffold trait and LLVM strip/rebind/rebase stays in
 * {@see CompileCacheEditScaffoldStrip}. Distinct from Strip mutation helpers.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache / file-cache deciding
 * which script units are invalidated vs reused on a partial project change
 * (Zend/zend_file_cache.c / Zend/zend_accelerator_hash.c shape).
 */
trait CompileCacheEditScaffoldPlan
{
    /**
     * Prefer stripping only symbols owned by changed members so unchanged LLVM
     * bodies stay in the module. Call sites that still point at `.stale` Values
     * are fixed by {@see rebaseStaleUserSymbols()} after re-lower (#36387).
     *
     * Only symbols attributed to an *unchanged* non-entry member are kept. Helpers
     * are never in {@see $allUserSymbols}; NestedJIT must not early-return on them.
     *
     * @param list<mixed>              $allUserSymbols
     * @param array<string, mixed>     $byMember
     *
     * @return list<string>
     */
    public static function userSymbolsToStripForEdit(array $allUserSymbols, array $byMember): array
    {
        $names = ['main' => true]; // C wrapper always recreated in compileToFile
        foreach ($allUserSymbols as $name) {
            if (is_string($name) && '' !== $name) {
                $names[$name] = true;
            }
        }

        $kept = self::computeKeptUserSymbols($allUserSymbols, $byMember);
        self::$keptUserSymbols = $kept;
        if ([] === $kept) {
            return array_keys($names);
        }

        foreach (array_keys($kept) as $name) {
            unset($names[$name]);
        }
        $names['main'] = true;

        return array_keys($names);
    }

    /**
     * True when changed-member attribution keeps at least one user symbol (#36387).
     *
     * @param list<mixed>          $allUserSymbols
     * @param array<string, mixed> $byMember
     */
    public static function wouldPartialStrip(array $allUserSymbols, array $byMember): bool
    {
        return [] !== self::computeKeptUserSymbols($allUserSymbols, $byMember);
    }

    /**
     * User LLVM names attributed solely to unchanged non-entry members (#36387).
     *
     * When a changed member has per-function attribution and
     * {@see $editChangedFunctions} lists only some methods, sibling method bodies
     * in that same file are kept (real one-method token edits).
     *
     * @param list<mixed>          $allUserSymbols
     * @param array<string, mixed> $byMember
     *
     * @return array<string, true>
     */
    public static function computeKeptUserSymbols(array $allUserSymbols, array $byMember): array
    {
        if ([] === $byMember) {
            return [];
        }

        $keepable = [];
        foreach ($allUserSymbols as $name) {
            if (
                is_string($name)
                && '' !== $name
                && 'main' !== $name
                && !str_starts_with($name, 'internal_')
            ) {
                $keepable[$name] = true;
            }
        }
        if ([] === $keepable) {
            return [];
        }

        $mustStripMember = [];
        foreach (self::$editChangedMembers as $member) {
            $mustStripMember[$member] = true;
        }
        // Entry ({main}) must re-lower when a changed member is a full-file strip
        // (config/const/glue) because folded constants and call sites may bake the
        // old values. Pure per-function body edits in other members leave entry
        // bytecode valid — keep it so MiniWebApp one-method edits stay ≤25% (#36387).
        if (is_string(self::$projectEntry) && '' !== self::$projectEntry) {
            $entry = self::$projectEntry;
            $keepEntry = !isset($mustStripMember[$entry]);
            if ($keepEntry) {
                foreach (array_keys($mustStripMember) as $member) {
                    $changedFns = self::$editChangedFunctions[$member] ?? null;
                    if (!is_array($changedFns) || [] === $changedFns) {
                        $keepEntry = false;
                        break;
                    }
                }
            }
            if (!$keepEntry) {
                $mustStripMember[$entry] = true;
            } elseif ([] !== $mustStripMember) {
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_entry_keep', 1.0);
            }
        }

        // Empty editChangedMembers after a byte-only (comment/whitespace) edit means every
        // attributed non-entry body is still semantically identical — keep them (#36387).
        // (Previously [] short-circuited to "keep nothing", undoing semantic_keep.)

        // Changed members missing from byMember (e.g. config.php with only assignments)
        // own no LLVM symbols — that must not abort keep-path for unchanged Router.php
        // (#36387 MiniWebApp one-file edit). Only require attribution when we need to
        // know which symbols the changed file owned; absence ⇒ empty strip set for it.
        $byFunction = self::$editScaffoldByFunction;
        $kept = [];
        foreach ($byMember as $member => $syms) {
            if (!is_string($member) || !is_array($syms)) {
                continue;
            }
            if (!isset($mustStripMember[$member])) {
                foreach ($syms as $sym) {
                    if (is_string($sym) && '' !== $sym && isset($keepable[$sym])) {
                        $kept[$sym] = true;
                    }
                }
                continue;
            }
            // Changed member: keep sibling functions when only some methods changed.
            $changedFns = self::$editChangedFunctions[$member] ?? null;
            $fnMap = $byFunction[$member] ?? null;
            if (!is_array($changedFns) || [] === $changedFns || !is_array($fnMap) || [] === $fnMap) {
                continue; // full strip of this member
            }
            foreach ($fnMap as $scoped => $fnSyms) {
                if (!is_string($scoped) || !is_array($fnSyms)) {
                    continue;
                }
                $scopedLc = strtolower($scoped);
                if (isset($changedFns[$scopedLc])) {
                    continue;
                }
                foreach ($fnSyms as $sym) {
                    if (is_string($sym) && '' !== $sym && isset($keepable[$sym])) {
                        $kept[$sym] = true;
                    }
                }
            }
        }

        if ([] === $kept) {
            return [];
        }

        // Symbols listed under a full-strip member, or under a changed function, must not stay.
        foreach (array_keys($mustStripMember) as $member) {
            $changedFns = self::$editChangedFunctions[$member] ?? null;
            $fnMap = $byFunction[$member] ?? null;
            if (is_array($changedFns) && [] !== $changedFns && is_array($fnMap) && [] !== $fnMap) {
                foreach ($fnMap as $scoped => $fnSyms) {
                    if (!is_string($scoped) || !is_array($fnSyms)) {
                        continue;
                    }
                    if (!isset($changedFns[strtolower($scoped)])) {
                        continue;
                    }
                    foreach ($fnSyms as $sym) {
                        if (is_string($sym)) {
                            unset($kept[$sym]);
                        }
                    }
                }
                continue;
            }
            $syms = $byMember[$member] ?? null;
            if (!is_array($syms)) {
                continue;
            }
            foreach ($syms as $sym) {
                if (is_string($sym)) {
                    unset($kept[$sym]);
                }
            }
        }

        return $kept;
    }
}
