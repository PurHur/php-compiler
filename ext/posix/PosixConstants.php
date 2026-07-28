<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\CompilerVersion;

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
    /** Linux extended resource ids (php-src POSIX_RLIMIT_*; #24130). */
    public const RLIMIT_LOCKS = 10;
    public const RLIMIT_SIGPENDING = 11;
    public const RLIMIT_MSGQUEUE = 12;
    public const RLIMIT_NICE = 13;
    public const RLIMIT_RTPRIO = 14;
    public const RLIMIT_RTTIME = 15;

    /** User-facing unlimited sentinel for posix_setrlimit() (php-src POSIX_RLIMIT_INFINITY). */
    public const RLIMIT_INFINITY = -1;

    /**
     * sysconf(3) / pathconf(3) name ids — Linux glibc unistd.h values.
     *
     * php-src: ext/posix/posix.stub.php POSIX_SC_* / POSIX_PC_* (#20509).
     */
    public const SC_ARG_MAX = 0;
    public const SC_CHILD_MAX = 1;
    public const SC_CLK_TCK = 2;
    public const SC_PAGESIZE = 30;
    public const SC_NPROCESSORS_CONF = 83;
    public const SC_NPROCESSORS_ONLN = 84;

    public const PC_LINK_MAX = 0;
    public const PC_MAX_CANON = 1;
    public const PC_MAX_INPUT = 2;
    public const PC_NAME_MAX = 3;
    public const PC_PATH_MAX = 4;
    public const PC_PIPE_BUF = 5;
    public const PC_CHOWN_RESTRICTED = 6;
    public const PC_NO_TRUNC = 7;
    public const PC_ALLOC_SIZE_MIN = 18;
    public const PC_SYMLINK_MAX = 19;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'POSIX_F_OK' => self::POSIX_F_OK,
            'POSIX_R_OK' => self::POSIX_R_OK,
            'POSIX_W_OK' => self::POSIX_W_OK,
            'POSIX_X_OK' => self::POSIX_X_OK,
            'POSIX_RLIMIT_CPU' => self::RLIMIT_CPU,
            'POSIX_RLIMIT_FSIZE' => self::RLIMIT_FSIZE,
            'POSIX_RLIMIT_DATA' => self::RLIMIT_DATA,
            'POSIX_RLIMIT_STACK' => self::RLIMIT_STACK,
            'POSIX_RLIMIT_CORE' => self::RLIMIT_CORE,
            'POSIX_RLIMIT_RSS' => self::RLIMIT_RSS,
            'POSIX_RLIMIT_NPROC' => self::RLIMIT_NPROC,
            'POSIX_RLIMIT_NOFILE' => self::RLIMIT_NOFILE,
            'POSIX_RLIMIT_MEMLOCK' => self::RLIMIT_MEMLOCK,
            'POSIX_RLIMIT_AS' => self::RLIMIT_AS,
            'POSIX_RLIMIT_LOCKS' => self::RLIMIT_LOCKS,
            'POSIX_RLIMIT_SIGPENDING' => self::RLIMIT_SIGPENDING,
            'POSIX_RLIMIT_MSGQUEUE' => self::RLIMIT_MSGQUEUE,
            'POSIX_RLIMIT_NICE' => self::RLIMIT_NICE,
            'POSIX_RLIMIT_RTPRIO' => self::RLIMIT_RTPRIO,
            'POSIX_RLIMIT_RTTIME' => self::RLIMIT_RTTIME,
            'POSIX_RLIMIT_INFINITY' => self::RLIMIT_INFINITY,
            // php-src registers POSIX_S_IF* (not bare S_IF* / not DIR/LNK) — #20517
            'POSIX_S_IFIFO' => self::S_IFIFO,
            'POSIX_S_IFCHR' => self::S_IFCHR,
            'POSIX_S_IFBLK' => self::S_IFBLK,
            'POSIX_S_IFREG' => self::S_IFREG,
            'POSIX_S_IFSOCK' => self::S_IFSOCK,
            // php-src POSIX_SC_* / POSIX_PC_* — PHP 8.3+ (#20509, #22483)
            ...(CompilerVersion::supportsPosixSysconfApis() ? [
                'POSIX_SC_ARG_MAX' => self::SC_ARG_MAX,
                'POSIX_SC_CHILD_MAX' => self::SC_CHILD_MAX,
                'POSIX_SC_CLK_TCK' => self::SC_CLK_TCK,
                'POSIX_SC_PAGESIZE' => self::SC_PAGESIZE,
                'POSIX_SC_NPROCESSORS_CONF' => self::SC_NPROCESSORS_CONF,
                'POSIX_SC_NPROCESSORS_ONLN' => self::SC_NPROCESSORS_ONLN,
                'POSIX_PC_LINK_MAX' => self::PC_LINK_MAX,
                'POSIX_PC_MAX_CANON' => self::PC_MAX_CANON,
                'POSIX_PC_MAX_INPUT' => self::PC_MAX_INPUT,
                'POSIX_PC_NAME_MAX' => self::PC_NAME_MAX,
                'POSIX_PC_PATH_MAX' => self::PC_PATH_MAX,
                'POSIX_PC_PIPE_BUF' => self::PC_PIPE_BUF,
                'POSIX_PC_CHOWN_RESTRICTED' => self::PC_CHOWN_RESTRICTED,
                'POSIX_PC_NO_TRUNC' => self::PC_NO_TRUNC,
                'POSIX_PC_ALLOC_SIZE_MIN' => self::PC_ALLOC_SIZE_MIN,
                'POSIX_PC_SYMLINK_MAX' => self::PC_SYMLINK_MAX,
            ] : []),
        ];
    }

    /** sysconf(_SC_CLK_TCK) fallback when unavailable. */
    public const CLK_TCK_FALLBACK = 100;
}
