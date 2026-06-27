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
        $globMark = 0 !== ($libcFlags & StdlibConstants::GLOB_MARK);
        $globBrace = 0 !== ($libcFlags & StdlibConstants::GLOB_BRACE);
        $noescape = 0 !== ($libcFlags & StdlibConstants::GLOB_NOESCAPE);
        $matchFlags = $libcFlags & ~StdlibConstants::GLOB_MARK & ~StdlibConstants::GLOB_BRACE;

        $patterns = [$pattern];
        if ($globBrace && (str_contains($pattern, '{') || str_contains($pattern, '}'))) {
            $patterns = self::expandBraces($pattern, $noescape);
        }

        $matches = [];
        foreach ($patterns as $expanded) {
            $result = self::globSingle($expanded, $matchFlags, $onlyDir);
            if (false === $result) {
                if (0 !== ($libcFlags & StdlibConstants::GLOB_ERR)) {
                    return false;
                }
                continue;
            }
            foreach ($result as $match) {
                $matches[] = $match;
            }
        }

        if (0 === ($matchFlags & StdlibConstants::GLOB_NOSORT)) {
            $matches = array_values(array_unique($matches));
            sort($matches, SORT_STRING);
        }

        if ($globMark) {
            $matches = self::applyGlobMark($matches);
        }

        return $matches;
    }

    /**
     * @return list<string>|false
     */
    private static function globSingle(string $pattern, int $libcFlags, bool $onlyDir)
    {
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

        return $matches;
    }

    /**
     * @return list<string>
     */
    private static function expandBraces(string $pattern, bool $noescape): array
    {
        $open = self::findUnescapedChar($pattern, '{', $noescape);
        if (false === $open) {
            return [$pattern];
        }
        $close = self::findMatchingCloseBrace($pattern, $open, $noescape);
        if (false === $close) {
            return [$pattern];
        }

        $prefix = substr($pattern, 0, $open);
        $suffix = substr($pattern, $close + 1);
        $inner = substr($pattern, $open + 1, $close - $open - 1);
        $alternatives = self::splitBraceAlternatives($inner, $noescape);
        if ([] === $alternatives) {
            return [$pattern];
        }

        $results = [];
        foreach ($alternatives as $alternative) {
            foreach (self::expandBraces($prefix.$alternative.$suffix, $noescape) as $expanded) {
                $results[] = $expanded;
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private static function splitBraceAlternatives(string $inner, bool $noescape): array
    {
        $alternatives = [];
        $current = '';
        $len = \strlen($inner);
        for ($i = 0; $i < $len; ++$i) {
            $char = $inner[$i];
            if (!$noescape && '\\' === $char && $i + 1 < $len) {
                $current .= $char.$inner[++$i];
                continue;
            }
            if (',' === $char) {
                $alternatives[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $alternatives[] = $current;

        return $alternatives;
    }

    private static function findUnescapedChar(string $pattern, string $char, bool $noescape): int|false
    {
        $len = \strlen($pattern);
        for ($i = 0; $i < $len; ++$i) {
            if ($pattern[$i] !== $char) {
                continue;
            }
            if (!$noescape && $i > 0 && '\\' === $pattern[$i - 1]) {
                continue;
            }

            return $i;
        }

        return false;
    }

    private static function findMatchingCloseBrace(string $pattern, int $open, bool $noescape): int|false
    {
        $len = \strlen($pattern);
        $depth = 0;
        for ($i = $open; $i < $len; ++$i) {
            $char = $pattern[$i];
            if (!$noescape && '\\' === $char && $i + 1 < $len) {
                ++$i;
                continue;
            }
            if ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $matches
     *
     * @return list<string>
     */
    private static function applyGlobMark(array $matches): array
    {
        $marked = [];
        foreach ($matches as $path) {
            if (self::pathIsDir($path)) {
                $marked[] = $path.'/';
            } else {
                $marked[] = $path;
            }
        }

        return $marked;
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
