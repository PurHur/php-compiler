<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * glob() for VM — pure PHP via {@see VmFsGlobPure} (#4859, #7314, #12208).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(glob)
 * JIT/AOT: StringFsGlobVecJit.php (LLVM from PHP, no injected C runtime)
 */
final class VmFsGlob
{
    public static function available(): bool
    {
        return VmFsGlobPure::available();
    }

    /**
     * @return list<string>|false
     */
    public static function glob(string $pattern, int $flags = 0)
    {
        return VmFsGlobPure::glob($pattern, $flags);
    }
}
