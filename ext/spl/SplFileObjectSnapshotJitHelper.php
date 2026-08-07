<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

/**
 * Thin AOT SplFileObject line snapshot (#28709).
 *
 * Takes file contents (already read via {@see __compiler_file_get_contents} libc path)
 * and splits like php-src fgets / SplFileObject iterator — including the trailing empty
 * line after a final newline.
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
        // Avoid NestedJIT-hostile while/strpos; explode + reattach "\n" matches fgets (#28709).
        $parts = \explode("\n", $contents);
        $n = \count($parts);
        $lines = [];
        for ($i = 0; $i < $n; ++$i) {
            if ($i === $n - 1) {
                $lines[] = $parts[$i];
            } else {
                $lines[] = $parts[$i]."\n";
            }
        }

        return $lines;
    }
}
