<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * glob() for VM — libc glob(3) via FFI or pure-PHP fallback (#4859, #7314, #7906).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(glob)
 * JIT/AOT: StringFsGlobVecJit.php (LLVM from PHP, no injected C runtime)
 *
 * Libc FFI or pure-PHP fallback only — no host glob builtin (bootstrap/M5, #7906).
 */
final class VmFsGlob
{
    private const GLOB_NOMATCH = 3;

    private static ?\FFI $ffi = null;

    /**
     * @return list<string>|false
     */
    public static function glob(string $pattern, int $flags = 0)
    {
        $onlyDir = 0 !== ($flags & StdlibConstants::GLOB_ONLYDIR);
        $libcFlags = $flags & StdlibConstants::GLOB_AVAILABLE_FLAGS & ~StdlibConstants::GLOB_ONLYDIR;

        if (self::ffiEnabled()) {
            $libc = self::libcGlob($pattern, $libcFlags, $onlyDir);
            if (null !== $libc) {
                return $libc;
            }
        }

        return self::globFallback($pattern, $libcFlags, $onlyDir);
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>|false|null null when FFI/libc path unavailable (use fallback)
     */
    private static function libcGlob(string $pattern, int $libcFlags, bool $onlyDir): array|false|null
    {
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            return null;
        }
        try {
            $ffi = self::ffi();
        } catch (\Throwable) {
            return null;
        }

        $glob = $ffi->new('glob_t');
        $rc = (int) $ffi->glob($pattern, $libcFlags, null, \FFI::addr($glob));
        if (self::GLOB_NOMATCH === $rc) {
            $ffi->globfree(\FFI::addr($glob));

            return [];
        }
        if (0 !== $rc) {
            $ffi->globfree(\FFI::addr($glob));

            return false;
        }

        $matches = [];
        $count = (int) $glob->gl_pathc;
        for ($i = 0; $i < $count; ++$i) {
            $path = \FFI::string($glob->gl_pathv[$i]);
            if ($onlyDir && !self::pathIsDir($path)) {
                continue;
            }
            $matches[] = $path;
        }
        $ffi->globfree(\FFI::addr($glob));

        return $matches;
    }

    private static function ffi(): \FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }

        $cdef = <<<'CDEF'
typedef struct {
    size_t gl_pathc;
    char **gl_pathv;
    size_t gl_offs;
    int gl_flags;
    void *gl_closedir;
    void *gl_readdir;
    void *gl_opendir;
    void *gl_lstat;
    void *gl_stat;
} glob_t;

int glob(const char *pattern, int flags, int (*errfunc)(const char *epath, int eerrno), glob_t *pglob);
void globfree(glob_t *pglob);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        throw new \RuntimeException('libc glob FFI unavailable');
    }

    /**
     * Pure-PHP fallback when libc FFI is absent.
     *
     * @return list<string>|false
     */
    private static function globFallback(string $pattern, int $libcFlags, bool $onlyDir)
    {
        if (str_contains($pattern, '{') || str_contains($pattern, '}')) {
            return false;
        }

        $dirEnd = strrpos($pattern, '/');
        if (false === $dirEnd) {
            $dir = '.';
            $filePattern = $pattern;
        } else {
            $dir = substr($pattern, 0, $dirEnd);
            if ('' === $dir) {
                $dir = '/';
            }
            $filePattern = substr($pattern, $dirEnd + 1);
        }

        if (!self::pathIsDir($dir) && '.' !== $dir) {
            return false;
        }

        $handle = @\opendir($dir);
        if (false === $handle) {
            return false;
        }

        $matches = [];
        while (false !== ($entry = \readdir($handle))) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (!VmFnmatch::match($filePattern, $entry, self::fnmatchFlagsFromGlob($libcFlags))) {
                continue;
            }
            $full = ('.' === $dir) ? $entry : ($dir.'/'.$entry);
            if ($onlyDir && !self::pathIsDir($full)) {
                continue;
            }
            $matches[] = $full;
        }
        \closedir($handle);

        if (0 === ($libcFlags & StdlibConstants::GLOB_NOSORT)) {
            sort($matches, SORT_STRING);
        }

        return $matches;
    }

    private static function fnmatchFlagsFromGlob(int $libcFlags): int
    {
        $fnm = 0;
        if (0 !== ($libcFlags & StdlibConstants::GLOB_NOESCAPE)) {
            $fnm |= VmFnmatch::FNM_NOESCAPE;
        }

        return $fnm;
    }

    private static function pathIsDir(string $path): bool
    {
        $stat = @\stat($path);
        if (false === $stat) {
            return false;
        }

        return ($stat['mode'] & 0xF000) === 0x4000;
    }
}
