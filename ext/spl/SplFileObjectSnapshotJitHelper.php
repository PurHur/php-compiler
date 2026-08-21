<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

/**
 * Thin AOT SplFileObject line snapshot (#28709 / #33308).
 *
 * Takes file contents (already read via {@see __compiler_file_get_contents} libc path)
 * and splits on "\n". NestedJIT of a for-loop that concatenates `$part."\n"` SIGSEGVs in
 * `__ref__delref` under thin AOT (#33308 gdb); explode-only is NestedJIT-safe. Callers that
 * trim() (foreach fixtures) match Zend; raw fgets newline retention can follow outside NestedJIT.
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI
 * (peer DirectoryIteratorSnapshotJitHelper #27289).
 *
 * php-src: ext/spl/spl_directory.c — spl_filesystem_file_read_line
 */
final class SplFileObjectSnapshotJitHelper
{
    /**
     * @return list<string>
     */
    public static function linesFromContentsArgv(?string $contents): array
    {
        if (null === $contents || '' === $contents) {
            // Empty file / open-at-EOF: one empty current line (#18429 / Zend foreach).
            return [''];
        }

        return \explode("\n", $contents);
    }
}
