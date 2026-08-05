<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmFsGlob;

/**
 * Thin glob snapshot for AOT GlobIterator (#27422).
 *
 * Uses {@see VmFsGlob::glob()} (NestedJIT embed path). Thin standalone AOT links
 * libc {@see __phpc_glob_vec} via {@see \PHPCompiler\JIT\Builtin\GlobIteratorSnapshotRuntime}.
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
        $globFlags = $flags & StdlibConstants::GLOB_AVAILABLE_FLAGS;
        $result = VmFsGlob::glob($pattern, $globFlags);
        $paths = false === $result ? [] : array_values($result);
        if (0 !== ($flags & self::FLAG_SKIP_DOTS)) {
            $paths = array_values(array_filter(
                $paths,
                static fn (string $path): bool => '.' !== basename($path) && '..' !== basename($path)
            ));
        }

        return $paths;
    }
}
