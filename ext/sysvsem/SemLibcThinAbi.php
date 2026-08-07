<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

/**
 * Thin libc ABI for System V semaphores (semget/semop/semctl) (#28431).
 *
 * Quarantines SysV sem FFI — no permanent runtime/*.c table.
 * php-src: ext/sysvsem/sysvsem.c
 */
final class SemLibcThinAbi
{
    public const IPC_CREAT = 512;

    public const IPC_EXCL = 1024;

    public const IPC_NOWAIT = 2048;

    public const IPC_RMID = 0;

    public const IPC_STAT = 2;

    public const GETVAL = 12;

    public const SETVAL = 16;

    public const SEM_UNDO = 4096;

    public const EINTR = 4;

    public const EAGAIN = 11;

    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function readErrno(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }

        $loc = $ffi->__errno_location();

        return (int) $loc[0];
    }

    public static function strerror(int $errno): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 'Unknown error '.$errno;
        }
        $ptr = $ffi->strerror($errno);
        if (null === $ptr) {
            return 'Unknown error '.$errno;
        }

        return \FFI::string($ptr);
    }

    public static function semget(int $key, int $nsems, int $semflg): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->semget($key, $nsems, $semflg);
    }

    /**
     * @param list<array{num: int, op: int, flg: int}> $ops
     */
    public static function semop(int $semid, array $ops): int
    {
        $ffi = self::ffi();
        $n = \count($ops);
        if (null === $ffi || $n < 1) {
            return -1;
        }
        $buf = $ffi->new('struct sembuf['.$n.']');
        for ($i = 0; $i < $n; ++$i) {
            $buf[$i]->sem_num = $ops[$i]['num'];
            $buf[$i]->sem_op = $ops[$i]['op'];
            $buf[$i]->sem_flg = $ops[$i]['flg'];
        }

        return (int) $ffi->semop($semid, $buf, $n);
    }

    public static function semctlGetval(int $semid, int $semnum): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->semctl($semid, $semnum, self::GETVAL, null);
    }

    public static function semctlSetval(int $semid, int $semnum, int $val): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $arg = $ffi->new('union semun');
        $arg->val = $val;

        return (int) $ffi->semctl($semid, $semnum, self::SETVAL, $arg);
    }

    public static function semctlStatOk(int $semid): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $buf = $ffi->new('struct semid_ds');
        $arg = $ffi->new('union semun');
        $arg->buf = \FFI::addr($buf);

        return 0 === (int) $ffi->semctl($semid, 0, self::IPC_STAT, $arg);
    }

    public static function semctlRmid(int $semid): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $buf = $ffi->new('struct semid_ds');
        $arg = $ffi->new('union semun');
        $arg->buf = \FFI::addr($buf);

        return (int) $ffi->semctl($semid, 0, self::IPC_RMID, $arg);
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (self::$unavailable || !\extension_loaded('ffi')) {
            self::$unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
struct ipc_perm {
    int __key;
    unsigned int uid;
    unsigned int gid;
    unsigned int cuid;
    unsigned int cgid;
    unsigned short mode;
    unsigned short __pad1;
    unsigned short __seq;
    unsigned short __pad2;
    unsigned long __unused1;
    unsigned long __unused2;
};
struct semid_ds {
    struct ipc_perm sem_perm;
    long sem_otime;
    unsigned long __unused1;
    long sem_ctime;
    unsigned long __unused2;
    unsigned long sem_nsems;
    unsigned long __unused3;
    unsigned long __unused4;
};
struct sembuf {
    unsigned short sem_num;
    short sem_op;
    short sem_flg;
};
union semun {
    int val;
    struct semid_ds *buf;
    unsigned short *array;
};
int semget(int key, int nsems, int semflg);
int semop(int semid, struct sembuf *sops, unsigned long nsops);
int semctl(int semid, int semnum, int cmd, ...);
int *__errno_location(void);
char *strerror(int errnum);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$unavailable = true;

        return null;
    }
}
