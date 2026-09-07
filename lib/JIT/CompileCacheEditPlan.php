<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Multi-file edit-plan session state for AOT CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so project member lists, bundled-source maps,
 * and semantic edit-changed member/function sets stay a separate TU while the hub
 * keeps recording + bitcode persist. Trait composition shares hub private statics
 * with {@see CompileCacheEditScaffold} / {@see CompileCacheBitcodePersist}.
 *
 * No new C ABI. php-src analogy: Zend opcache invalidation keys which scripts in a
 * multi-file project changed (Zend/zend_accelerator_hash.c / file-cache shape) —
 * here the plan drives which LLVM bodies the edit scaffold must strip vs keep.
 */
trait CompileCacheEditPlan
{
    /** @var list<string>|null absolute member paths for multi-file project index (#36387). */
    private static ?array $projectMembers = null;

    /** Absolute entry script path (before setProjectMembers sort) (#36387). */
    private static ?string $projectEntry = null;

    private static ?string $bundledSource = null;

    /**
     * Absolute paths whose *semantic* content changed vs the scaffold project index
     * (comments/whitespace-only edits do not strip that member's LLVM bodies) (#36387).
     *
     * @var list<string>
     */
    private static array $editChangedMembers = [];

    /**
     * Within a semantically-changed member: scoped names (lc) whose bodies changed.
     * Absent path ⇒ full member strip; non-empty ⇒ keep sibling functions (#36387).
     *
     * @var array<string, array<string, true>>
     */
    private static array $editChangedFunctions = [];

    /**
     * @param list<string> $absolutePaths entry + includes (pre-bundle)
     */
    public static function setProjectMembers(array $absolutePaths): void
    {
        $clean = [];
        foreach ($absolutePaths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $resolved = realpath($path);
            $clean[] = false !== $resolved ? $resolved : $path;
        }
        // First path is the compile entry (compile.php merges entry + includes) (#36387).
        self::$projectEntry = $clean[0] ?? null;
        $clean = array_values(array_unique($clean));
        sort($clean);
        self::$projectMembers = $clean;
    }

    /** @return list<string> */
    public static function projectMembers(): array
    {
        return self::$projectMembers ?? [];
    }

    /** Compile entry path captured by {@see setProjectMembers()} (#36387). */
    public static function projectEntry(): ?string
    {
        return self::$projectEntry;
    }

    public static function setBundledSource(string $source): void
    {
        self::$bundledSource = $source;
    }

    /**
     * @param list<string> $paths
     */
    public static function setEditChangedMembers(array $paths): void
    {
        $clean = [];
        foreach ($paths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $resolved = realpath($path);
            $clean[] = false !== $resolved ? $resolved : $path;
        }
        self::$editChangedMembers = array_values(array_unique($clean));
    }

    /** @return list<string> */
    public static function editChangedMembers(): array
    {
        return self::$editChangedMembers;
    }

    /**
     * @param array<string, array<string, true|int|string>> $pathToScoped
     */
    public static function setEditChangedFunctions(array $pathToScoped): void
    {
        $clean = [];
        foreach ($pathToScoped as $path => $scopedMap) {
            if (!is_string($path) || '' === $path || !is_array($scopedMap)) {
                continue;
            }
            $resolved = realpath($path);
            $key = false !== $resolved ? $resolved : $path;
            $funcs = [];
            foreach ($scopedMap as $scoped => $flag) {
                if (!is_string($scoped) || '' === $scoped) {
                    continue;
                }
                if (false === $flag || null === $flag) {
                    continue;
                }
                $funcs[strtolower($scoped)] = true;
            }
            if ([] !== $funcs) {
                $clean[$key] = $funcs;
            }
        }
        self::$editChangedFunctions = $clean;
    }

    /** @return array<string, array<string, true>> */
    public static function editChangedFunctions(): array
    {
        return self::$editChangedFunctions;
    }

    /** Clear edit-plan session fields (called from {@see CompileCache::finishRecording()}). */
    private static function clearEditPlan(): void
    {
        self::$bundledSource = null;
        self::$editChangedMembers = [];
        self::$editChangedFunctions = [];
        self::$projectMembers = null;
        self::$projectEntry = null;
    }
}
