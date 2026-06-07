<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * POSIX access/mknod constants (php-src ext/posix/posix.stub.php; #7376).
 */
final class PosixConstants
{
    public const POSIX_F_OK = 0;
    public const POSIX_R_OK = 4;
    public const POSIX_W_OK = 2;
    public const POSIX_X_OK = 1;

    /** @see sys/stat.h S_IFMT / S_IF* — php-src ext/posix/posix.stub.php */
    public const S_IFMT = 0170000;
    public const S_IFIFO = 0010000;
    public const S_IFCHR = 0020000;
    public const S_IFDIR = 0040000;
    public const S_IFBLK = 0060000;
    public const S_IFREG = 0100000;
    public const S_IFLNK = 0120000;
    public const S_IFSOCK = 0140000;
}
