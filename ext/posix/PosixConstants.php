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

    /** php-src ext/posix/posix.c — RLIMIT_* resource ids (Linux). */
    public const RLIMIT_CPU = 0;
    public const RLIMIT_FSIZE = 1;
    public const RLIMIT_DATA = 2;
    public const RLIMIT_STACK = 3;
    public const RLIMIT_CORE = 4;
    public const RLIMIT_RSS = 5;
    public const RLIMIT_NPROC = 6;
    public const RLIMIT_NOFILE = 7;
    public const RLIMIT_MEMLOCK = 8;
    public const RLIMIT_AS = 9;

    /** User-facing unlimited sentinel for posix_setrlimit() (php-src POSIX_RLIMIT_INFINITY). */
    public const RLIMIT_INFINITY = -1;

    /** sysconf(_SC_CLK_TCK) fallback when unavailable. */
    public const CLK_TCK_FALLBACK = 100;
}
