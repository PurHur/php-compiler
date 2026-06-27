<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_readable/is_writable/is_executable via stat mode bits — no libc access(2) FFI (#8990).
 *
 * php-src: ext/standard/filestat.c — FS_IS_R / FS_IS_W / FS_IS_X stat path (lines 858–938).
 */
final class VmFsAccessPure
{
    private const S_IRUSR = 0x0100;

    private const S_IWUSR = 0x0080;

    private const S_IXUSR = 0x0040;

    private const S_IRGRP = 0x0020;

    private const S_IWGRP = 0x0010;

    private const S_IXGRP = 0x0008;

    private const S_IROTH = 0x0004;

    private const S_IWOTH = 0x0002;

    private const S_IXOTH = 0x0001;

    /** php-src S_IXROOT — root execute traverse mask */
    private const S_IXROOT = self::S_IRUSR | self::S_IWUSR | self::S_IXUSR | self::S_IXGRP | self::S_IROTH | self::S_IXOTH;

    public static function isReadable(string $path): bool
    {
        return self::access($path, VmFsAccessNative::R_OK);
    }

    public static function isWritable(string $path): bool
    {
        return self::access($path, VmFsAccessNative::W_OK);
    }

    public static function isExecutable(string $path): bool
    {
        return self::access($path, VmFsAccessNative::X_OK);
    }

    public static function access(string $path, int $mode): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return self::modeAllows($stat, $mode);
    }

    /**
     * @param array<int|string, int> $stat
     */
    private static function modeAllows(array $stat, int $accessMode): bool
    {
        if (!VmProcessIdentityNative::available()) {
            return false;
        }
        $uid = VmProcessIdentityNative::getuid();
        $gid = VmProcessIdentityNative::getgid();
        if (null === $uid || null === $gid) {
            return false;
        }

        $fileMode = (int) $stat['mode'];
        $fileUid = (int) $stat['uid'];
        $fileGid = (int) $stat['gid'];

        $rmask = self::S_IROTH;
        $wmask = self::S_IWOTH;
        $xmask = self::S_IXOTH;

        if ($fileUid === $uid) {
            $rmask = self::S_IRUSR;
            $wmask = self::S_IWUSR;
            $xmask = self::S_IXUSR;
        } elseif ($fileGid === $gid || self::gidInSupplementaryGroups($fileGid)) {
            $rmask = self::S_IRGRP;
            $wmask = self::S_IWGRP;
            $xmask = self::S_IXGRP;
        }

        if (0 === $uid) {
            if (0 !== ($accessMode & VmFsAccessNative::X_OK)) {
                return self::rootExecutableAllowed($fileMode);
            }

            return true;
        }

        $permMask = 0;
        if (0 !== ($accessMode & VmFsAccessNative::R_OK)) {
            $permMask = $rmask;
        } elseif (0 !== ($accessMode & VmFsAccessNative::W_OK)) {
            $permMask = $wmask;
        } elseif (0 !== ($accessMode & VmFsAccessNative::X_OK)) {
            $permMask = $xmask;
        }

        return 0 !== ($fileMode & $permMask);
    }

    /** Root execute parity with access(2): dirs always ok; files need any execute bit. */
    private static function rootExecutableAllowed(int $fileMode): bool
    {
        if (0x4000 === ($fileMode & 0xF000)) {
            return true;
        }

        return 0 !== ($fileMode & (self::S_IXUSR | self::S_IXGRP | self::S_IXOTH));
    }

    private static function gidInSupplementaryGroups(int $gid): bool
    {
        $groups = VmProcessIdentityNative::getgroups();
        if (null === $groups) {
            return false;
        }

        return \in_array($gid, $groups, true);
    }
}
