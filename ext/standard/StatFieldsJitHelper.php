<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Stat field reads for compiled JIT/AOT modules (#9112, php-in-PHP).
 *
 * VM SSOT: {@see VmStatCache}, {@see VmFs}, {@see VmFsDiskNative}
 * php-src: ext/standard/filestat.c
 */
final class StatFieldsJitHelper
{
    public const FIELD_SIZE = 0;

    public const FIELD_MTIME = 1;

    public const FIELD_ATIME = 2;

    public const FIELD_CTIME = 3;

    public const FIELD_INO = 4;

    public const FIELD_UID = 5;

    public const FIELD_GID = 6;

    public const FIELD_DEV = 7;

    public const FIELD_MODE = 8;

    /** @return int field value, or -1 on failure (LLVM i64 ABI) */
    public static function longField(string $path, int $useLstat, int $fieldId): int
    {
        if ('' === $path) {
            return -1;
        }
        $key = self::fieldKey($fieldId);
        if (null === $key) {
            return -1;
        }
        $raw = 0 !== $useLstat ? VmStatCache::lstat($path) : VmStatCache::stat($path);
        if (false === $raw) {
            return -1;
        }

        if (isset($raw[$key])) {
            return (int) $raw[$key];
        }

        return -1;
    }

    private static function fieldKey(int $fieldId): ?string
    {
        switch ($fieldId) {
            case self::FIELD_SIZE:
                return 'size';
            case self::FIELD_MTIME:
                return 'mtime';
            case self::FIELD_ATIME:
                return 'atime';
            case self::FIELD_CTIME:
                return 'ctime';
            case self::FIELD_INO:
                return 'ino';
            case self::FIELD_UID:
                return 'uid';
            case self::FIELD_GID:
                return 'gid';
            case self::FIELD_DEV:
                return 'dev';
            case self::FIELD_MODE:
                return 'mode';
            default:
                return null;
        }
    }

    /** @return string filetype label, or empty string when lstat fails */
    public static function filetypeLabel(string $path): string
    {
        $type = VmFs::fileType($path);

        return false === $type ? '' : $type;
    }

    /** @return int bytes free, or -1 on failure */
    public static function diskFreeBytes(string $path): int
    {
        $result = VmFsDiskNative::diskFreeSpace($path);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    /** @return int bytes total, or -1 on failure */
    public static function diskTotalBytes(string $path): int
    {
        $result = VmFsDiskNative::diskTotalSpace($path);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }
}
