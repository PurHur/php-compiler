<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

/**
 * NestedJIT helpers for shmop_* via ShmopLibcThinAbi (#27408).
 *
 * Self-contained segment map so thin AOT NestedJIT does not need VmShmop.
 * Bundle with {@see ShmopLibcThinAbi} via JitVmHelperLink::ensureCompiledBundle.
 * php-src: ext/shmop/shmop.c
 */
final class ShmopJitHelper
{
    /** @var array<int, array{shmid: int, addr: int, size: int, readonly: bool}> */
    private static array $owned = [];

    private static int $pendingShmid = -1;

    private static int $pendingAddr = 0;

    private static int $pendingSize = 0;

    private static int $pendingReadonly = 0;

    /**
     * Open segment — returns shmid (>=0) or -1 on failure.
     * Mode is first character ordinal ('a'/'c'/'n'/'w').
     */
    public static function openArgv(int $key, int $modeChar, int $permissions, int $size): int
    {
        self::$pendingShmid = -1;
        self::$pendingAddr = 0;
        self::$pendingSize = 0;
        self::$pendingReadonly = 0;
        if (!ShmopLibcThinAbi::available()) {
            return -1;
        }
        $mode = \chr($modeChar & 0xff);
        try {
            $seg = self::openLibc($key, $mode, $permissions, $size);
        } catch (\ValueError $e) {
            throw $e;
        }
        if (null === $seg) {
            return -1;
        }
        self::$pendingShmid = $seg['shmid'];
        self::$pendingAddr = $seg['addr'];
        self::$pendingSize = $seg['size'];
        self::$pendingReadonly = $seg['readonly'] ? 1 : 0;

        return $seg['shmid'];
    }

    public static function pendingAddrArgv(): int
    {
        return self::$pendingAddr;
    }

    public static function pendingSizeArgv(): int
    {
        return self::$pendingSize;
    }

    public static function pendingReadonlyArgv(): int
    {
        return self::$pendingReadonly;
    }

    public static function registerOwnedArgv(
        int $objAddr,
        int $shmid,
        int $addr,
        int $size,
        int $readonly
    ): void {
        if ($objAddr <= 0 || $shmid < 0 || 0 === $addr) {
            return;
        }
        self::$owned[$objAddr] = [
            'shmid' => $shmid,
            'addr' => $addr,
            'size' => $size,
            'readonly' => 1 === $readonly,
        ];
    }

    public static function sizeArgv(int $handle): int
    {
        $seg = self::$owned[$handle] ?? null;

        return null === $seg ? -1 : $seg['size'];
    }

    public static function deleteArgv(int $handle): int
    {
        $seg = self::$owned[$handle] ?? null;
        if (null === $seg) {
            return 0;
        }

        return 0 === ShmopLibcThinAbi::shmctlRmid($seg['shmid']) ? 1 : 0;
    }

    /** php-src shmop_close is a NOP. */
    public static function closeArgv(int $handle): void
    {
    }

    public static function readArgv(int $handle, int $start, int $count): string
    {
        $seg = self::$owned[$handle] ?? null;
        if (null === $seg) {
            return '';
        }
        if ($start < 0 || $start > $seg['size']) {
            throw new \ValueError('shmop_read(): Argument #2 ($offset) must be between 0 and the segment size');
        }
        if ($count < 0 || $start > (PHP_INT_MAX - $count) || $start + $count > $seg['size']) {
            throw new \ValueError('shmop_read(): Argument #3 ($size) is out of range');
        }
        $bytes = 0 !== $count ? $count : $seg['size'] - $start;
        if ($bytes < 1) {
            return '';
        }

        return ShmopLibcThinAbi::memcpyFrom($seg['addr'] + $start, $bytes);
    }

    public static function writeArgv(int $handle, string $data, int $offset): int
    {
        $seg = self::$owned[$handle] ?? null;
        if (null === $seg) {
            return -1;
        }
        if ($seg['readonly']) {
            throw new \Error('Read-only segment cannot be written');
        }
        if ($offset < 0 || $offset > $seg['size']) {
            throw new \ValueError('shmop_write(): Argument #3 ($offset) is out of range');
        }
        $writesize = \strlen($data) < ($seg['size'] - $offset) ? \strlen($data) : $seg['size'] - $offset;
        if ($writesize > 0) {
            ShmopLibcThinAbi::memcpyTo($seg['addr'] + $offset, $data, $writesize);
        }

        return $writesize;
    }

    /**
     * @return array{shmid: int, addr: int, size: int, readonly: bool}|null
     */
    private static function openLibc(int $key, string $mode, int $permissions, int $size): ?array
    {
        if (1 !== \strlen($mode)) {
            throw new \ValueError('shmop_open(): Argument #2 ($mode) must be a valid access mode');
        }
        $shmflg = $permissions & 0o777;
        $shmatflg = 0;
        $createSize = 0;
        switch ($mode[0]) {
            case 'a':
                $shmatflg |= ShmopLibcThinAbi::SHM_RDONLY;
                break;
            case 'c':
                $shmflg |= ShmopLibcThinAbi::IPC_CREAT;
                $createSize = $size;
                break;
            case 'n':
                $shmflg |= ShmopLibcThinAbi::IPC_CREAT | ShmopLibcThinAbi::IPC_EXCL;
                $createSize = $size;
                break;
            case 'w':
                break;
            default:
                throw new \ValueError('shmop_open(): Argument #2 ($mode) must be a valid access mode');
        }
        if (($shmflg & ShmopLibcThinAbi::IPC_CREAT) !== 0 && $createSize < 1) {
            throw new \ValueError(
                'shmop_open(): Argument #4 ($size) must be greater than 0 for the "c" and "n" access modes'
            );
        }
        $shmid = ShmopLibcThinAbi::shmget($key, $createSize, $shmflg);
        if ($shmid < 0) {
            return null;
        }
        $segsz = ShmopLibcThinAbi::shmctlStatSize($shmid);
        if ($segsz < 0) {
            return null;
        }
        $addr = ShmopLibcThinAbi::shmat($shmid, $shmatflg);
        if (0 === $addr) {
            return null;
        }

        return [
            'shmid' => $shmid,
            'addr' => $addr,
            'size' => $segsz,
            'readonly' => ($shmatflg & ShmopLibcThinAbi::SHM_RDONLY) !== 0,
        ];
    }
}
