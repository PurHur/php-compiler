<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Multi-file project member list for AOT CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so entry + include realpaths (and the compile
 * entry path captured before sort) stay a separate TU while recording / bitcode /
 * edit-scaffold traits share the props via hub composition.
 *
 * No new C ABI. php-src analogy: Zend opcache keys a multi-file project by the set of
 * scripts that form the compiled image (Zend/zend_accelerator_hash.c shape).
 */
trait CompileCacheProjectMembers
{
    /** @var list<string>|null absolute member paths for multi-file project index (#36387). */
    private static ?array $projectMembers = null;

    /** Absolute entry script path (before setProjectMembers sort) (#36387). */
    private static ?string $projectEntry = null;

    /**
     * Record absolute source paths that make up a multi-file AOT project (#36387).
     *
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
}
