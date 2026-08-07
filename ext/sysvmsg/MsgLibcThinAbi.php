<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

/**
 * Thin libc ABI for System V message queues (#28432).
 *
 * Quarantines SysV msg FFI — no permanent runtime/*.c table.
 * Thin-AOT uses {@see \PHPCompiler\JIT\Builtin\MsgRuntime} LLVM instead
 * (NestedJIT FFI unreliable under thin AOT — peer #28431 / #28433).
 * php-src: ext/sysvmsg/sysvmsg.c
 */
final class MsgLibcThinAbi
{
    public const IPC_CREAT = 512;

    public const IPC_EXCL = 1024;

    public const IPC_NOWAIT = 2048;

    public const IPC_RMID = 0;

    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function msgget(int $key, int $msgflg): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->msgget($key, $msgflg);
    }

    public static function msgctlRmid(int $msqid): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->msgctl($msqid, self::IPC_RMID, null);
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
int msgget(int key, int msgflg);
int msgctl(int msqid, int cmd, void *buf);
int msgsnd(int msqid, const void *msgp, unsigned long msgsz, int msgflg);
long msgrcv(int msqid, void *msgp, unsigned long msgsz, long msgtyp, int msgflg);
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
