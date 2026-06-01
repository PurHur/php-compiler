<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM helpers for md5_file() / sha1_file() (issue #3590, ext/standard/md5.c parity).
 */
final class VmHashFile
{
    /**
     * @return string|false hex or raw digest
     */
    public static function hashFile(string $algo, string $path, bool $raw = false) {
        $data = VmFs::fileGetContents($path);
        if (false === $data) {
            return false;
        }

        return VmHash::hash($algo, $data, $raw);
    }
}
