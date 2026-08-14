<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\PathSupport;

/**
 * VM helpers for md5_file() / sha1_file() (issue #3590, ext/standard/md5.c parity).
 */
final class VmHashFile
{
    /**
     * @return string|false hex or raw digest
     */
    public static function hashFile(string $algo, string $path, bool $raw = false) {
        self::rejectEmptyPath($path);
        VmHash::ensureDigestAlgo($algo, 'hash_file');
        $data = VmFs::fileGetContents($path);
        if (false === $data) {
            return false;
        }

        return VmHash::hash($algo, $data, $raw, 'hash_file');
    }

    /**
     * @return string|false hex or raw digest
     */
    public static function hashHmacFile(string $algo, string $path, string $key, bool $raw = false) {
        self::rejectEmptyPath($path);
        // php-src PHP_FUNCTION(hash_hmac_file) — unknown algo cites hash_hmac_file() (#30646).
        VmHash::ensureHmacAlgo($algo, 'hash_hmac_file');
        $data = VmFs::fileGetContents($path);
        if (false === $data) {
            return false;
        }

        return VmHash::hashHmac($algo, $data, $key, $raw, 'hash_hmac_file');
    }

    /** @throws \ValueError php-src Z_PARAM_PATH empty-string guard (#14074). */
    private static function rejectEmptyPath(string $path): void
    {
        if (PathSupport::isEmptyPath($path)) {
            throw new \ValueError(PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
        }
    }
}
