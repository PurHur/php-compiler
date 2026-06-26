<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * glob() without libc glob(3) FFI — VmFnmatch + VmDirNative::listSorted (#12208, #7906, #8167).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(glob)
 */
final class VmFsGlobPure
{
    public static function available(): bool
    {
        return true;
    }

    /**
     * @return list<string>|false
     */
    public static function glob(string $pattern, int $flags = 0)
    {
        $onlyDir = 0 !== ($flags & StdlibConstants::GLOB_ONLYDIR);
        $libcFlags = $flags & StdlibConstants::GLOB_AVAILABLE_FLAGS & ~StdlibConstants::GLOB_ONLYDIR;

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

        $entries = VmDirNative::listSorted($dir);
        if (false === $entries) {
            return false;
        }

        $matches = [];
        foreach ($entries as $entry) {
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
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return ($stat['mode'] & 0xF000) === 0x4000;
    }
}
