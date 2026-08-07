<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

/**
 * Thin libc ABI for System V shmop (shmget/shmat/shmdt/shmctl) (#27408).
 *
 * Quarantines SysV shm FFI — no permanent runtime/*.c table.
 * php-src: ext/shmop/shmop.c
 */
final class ShmopLibcThinAbi
{
    public const IPC_CREAT = 512;

    public const IPC_EXCL = 1024;

    public const IPC_RMID = 0;

    public const IPC_STAT = 2;

    public const SHM_RDONLY = 4096;

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

    public static function shmget(int $key, int $size, int $shmflg): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->shmget($key, $size, $shmflg);
    }

    /** @return int address as integer, or 0 on failure ((void*)-1). */
    public static function shmat(int $shmid, int $shmflg): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }
        $ptr = $ffi->shmat($shmid, null, $shmflg);
        if (null === $ptr) {
            return 0;
        }
        $addr = self::pointerToInt($ffi, $ptr);
        if (-1 === $addr) {
            return 0;
        }

        return $addr;
    }

    public static function shmdt(int $addr): int
    {
        $ffi = self::ffi();
        if (null === $ffi || 0 === $addr || -1 === $addr) {
            return -1;
        }
        $ptr = self::intToPointer($ffi, $addr);

        return (int) $ffi->shmdt($ptr);
    }

    /**
     * @return int segment size, or -1 on failure
     */
    public static function shmctlStatSize(int $shmid): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $buf = $ffi->new('struct shmid_ds');
        $rc = (int) $ffi->shmctl($shmid, self::IPC_STAT, \FFI::addr($buf));
        if (0 !== $rc) {
            return -1;
        }

        return (int) $buf->shm_segsz;
    }

    public static function shmctlRmid(int $shmid): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->shmctl($shmid, self::IPC_RMID, null);
    }

    public static function memcpyTo(int $dstAddr, string $data, int $len): void
    {
        $ffi = self::ffi();
        if (null === $ffi || 0 === $dstAddr || -1 === $dstAddr || $len < 1) {
            return;
        }
        $src = $ffi->new('char['.$len.']');
        \FFI::memcpy($src, $data, $len);
        $dst = self::intToPointer($ffi, $dstAddr);
        $ffi->memcpy($dst, $src, $len);
    }

    public static function memcpyFrom(int $srcAddr, int $len): string
    {
        $ffi = self::ffi();
        if (null === $ffi || 0 === $srcAddr || -1 === $srcAddr || $len < 1) {
            return '';
        }
        $ptr = $ffi->cast('char*', self::intToPointer($ffi, $srcAddr));

        return \FFI::string($ptr, $len);
    }

    /** @param \FFI\CData $ptr */
    private static function pointerToInt(\FFI $ffi, $ptr): int
    {
        $box = $ffi->new('uintptr_t');
        \FFI::memcpy(\FFI::addr($box), \FFI::addr($ptr), \FFI::sizeof($box));

        return (int) $box->cdata;
    }

    /** @return \FFI\CData */
    private static function intToPointer(\FFI $ffi, int $addr)
    {
        return $ffi->cast('void*', $addr);
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
struct shmid_ds {
    struct ipc_perm shm_perm;
    unsigned long shm_segsz;
    long shm_atime;
    long shm_dtime;
    long shm_ctime;
    int shm_cpid;
    int shm_lpid;
    unsigned long shm_nattch;
    unsigned long __unused4;
    unsigned long __unused5;
};
int shmget(int key, unsigned long size, int shmflg);
void *shmat(int shmid, const void *shmaddr, int shmflg);
int shmdt(const void *shmaddr);
int shmctl(int shmid, int cmd, void *buf);
int *__errno_location(void);
char *strerror(int errnum);
void *memcpy(void *dest, const void *src, unsigned long n);
typedef unsigned long uintptr_t;
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
