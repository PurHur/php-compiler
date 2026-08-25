<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\StdlibConstants;

/**
 * Thin glob snapshot for AOT GlobIterator (#27422, #34628).
 *
 * NestedJIT `\glob()` directly (same whitelist leaf as user-script glob — works under
 * thin AOT). Do not call {@see \PHPCompiler\ext\standard\VmFsGlob::glob} from this
 * helper — NestedJIT of that indirection returns null and `array_values(null)` TypeErrors
 * (#34628 / peer DirectoryIteratorSnapshotJitHelper #33263).
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI
 * (peer DirectoryIteratorSnapshotJitHelper #27289).
 *
 * php-src: ext/spl/spl_directory.c — GlobIterator
 */
final class GlobIteratorSnapshotJitHelper
{
    private const FLAG_SKIP_DOTS = 4096;

    /**
     * @return list<string>
     */
    public static function entriesArgv(string $pattern, int $flags): array
    {
        // php-src GlobIterator stores FilesystemIterator flags separately from glob()
        // flags — only GLOB_* bits are passed to php_glob (#24254).
        $globFlags = $flags & StdlibConstants::GLOB_AVAILABLE_FLAGS;
        // Call glob() directly so NestedJIT hits the same whitelist leaf as user-script
        // glob() (which works under thin AOT). VmFsGlob::glob nested from this helper
        // returns null (#34628 / peer DI #33263).
        $result = \glob($pattern, $globFlags);
        $paths = !\is_array($result) ? [] : array_values($result);
        if (0 !== ($flags & self::FLAG_SKIP_DOTS)) {
            $paths = array_values(array_filter(
                $paths,
                static fn (string $path): bool => '.' !== basename($path) && '..' !== basename($path)
            ));
        }

        return $paths;
    }
}
