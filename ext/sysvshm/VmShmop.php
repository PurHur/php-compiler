<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * System V shared memory byte I/O — host shmop delegation (php-src ext/sysvshm/shmop.c; #3344).
 *
 * PHP-in-PHP: Shmop VM objects map to host {@see \Shmop} handles.
 * JIT/AOT owned segments use {@see ShmopLibcThinAbi} keyed by object address (#27408).
 */
final class VmShmop
{
    public const CLASS_LC = 'shmop';

    /** @var array<int, object> VM object id => host Shmop */
    private static array $hostByObjectId = [];

    /** @var array<int, array{shmid: int, addr: int, size: int, readonly: bool}> */
    private static array $jitOwnedByHandle = [];

    public static function available(): bool
    {
        return \function_exists('shmop_open') || ShmopLibcThinAbi::available();
    }

    public static function hostAvailable(): bool
    {
        return \function_exists('shmop_open');
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Shmop');
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrapHost(Context $ctx, object $host): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$hostByObjectId[$object->id] = $host;
        $var->object($object);

        return $var;
    }

    public static function hostForObject(ObjectEntry $object): ?object
    {
        if (0 !== strcasecmp($object->class->name, 'Shmop')) {
            return null;
        }

        return self::$hostByObjectId[$object->id] ?? null;
    }

    public static function detachObject(ObjectEntry $object): void
    {
        unset(self::$hostByObjectId[$object->id]);
    }

    public static function isShmopObject(?ObjectEntry $object): bool
    {
        return null !== $object && 0 === strcasecmp($object->class->name, 'Shmop');
    }

    /**
     * @return array{0: Variable|false, 1: string}
     */
    public static function open(Context $ctx, int $key, string $mode, int $permissions, int $size): array
    {
        if (self::hostAvailable()) {
            $host = @\shmop_open($key, $mode, $permissions, $size);
            if (false === $host || !\is_object($host)) {
                $last = \error_get_last();
                $message = \is_array($last) && isset($last['message']) ? (string) $last['message'] : 'shmop_open() failed';

                return [false, $message];
            }

            return [self::wrapHost($ctx, $host), ''];
        }

        if (!ShmopLibcThinAbi::available()) {
            return [false, 'shmop_open() is not available in this compiler build'];
        }

        $opened = self::openLibc($key, $mode, $permissions, $size);
        if (null === $opened) {
            $errno = ShmopLibcThinAbi::readErrno();
            $message = 'Unable to attach or create shared memory segment "'.ShmopLibcThinAbi::strerror($errno).'"';

            return [false, $message];
        }

        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$jitOwnedByHandle[$object->id] = $opened;
        $var->object($object);

        return [$var, ''];
    }

    /**
     * JIT/AOT — register libc-owned segment by object address (#27408).
     *
     * @param array{shmid: int, addr: int, size: int, readonly: bool} $segment
     */
    public static function registerJitOwned(int $objAddr, array $segment): void
    {
        if ($objAddr <= 0 || $segment['shmid'] < 0 || 0 === $segment['addr']) {
            return;
        }
        self::$jitOwnedByHandle[$objAddr] = $segment;
    }

    /** @return array{shmid: int, addr: int, size: int, readonly: bool}|null */
    public static function jitOwnedForLookupKey(int $key): ?array
    {
        if ($key <= 0) {
            return null;
        }

        return self::$jitOwnedByHandle[$key] ?? null;
    }

    public static function releaseJitOwned(int $key): void
    {
        if ($key <= 0) {
            return;
        }
        unset(self::$jitOwnedByHandle[$key]);
    }

    /**
     * @return array{shmid: int, addr: int, size: int, readonly: bool}|null
     */
    public static function openLibc(int $key, string $mode, int $permissions, int $size): ?array
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

    /**
     * @return string|false
     */
    public static function read(object $host, int $start, int $count): string|false
    {
        if (!self::hostAvailable()) {
            return false;
        }

        return @\shmop_read($host, $start, $count);
    }

    /** @return string|false */
    public static function readForObject(ObjectEntry $object, int $start, int $count): string|false
    {
        $host = self::hostForObject($object);
        if (null !== $host) {
            return self::read($host, $start, $count);
        }
        $seg = self::jitOwnedForLookupKey($object->id);
        if (null === $seg) {
            return false;
        }
        if ($start < 0 || $start > $seg['size']) {
            throw new \ValueError('shmop_read(): Argument #2 ($offset) must be between 0 and the segment size');
        }
        if ($count < 0 || $start > (PHP_INT_MAX - $count) || $start + $count > $seg['size']) {
            throw new \ValueError('shmop_read(): Argument #3 ($size) is out of range');
        }
        $bytes = 0 !== $count ? $count : $seg['size'] - $start;

        return ShmopLibcThinAbi::memcpyFrom($seg['addr'] + $start, $bytes);
    }

    public static function write(object $host, string $data, int $offset): int|false
    {
        if (!self::hostAvailable()) {
            return false;
        }

        return @\shmop_write($host, $data, $offset);
    }

    public static function writeForObject(ObjectEntry $object, string $data, int $offset): int|false
    {
        $host = self::hostForObject($object);
        if (null !== $host) {
            return self::write($host, $data, $offset);
        }
        $seg = self::jitOwnedForLookupKey($object->id);
        if (null === $seg) {
            return false;
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

    public static function size(object $host): int|false
    {
        if (!self::hostAvailable()) {
            return false;
        }

        return @\shmop_size($host);
    }

    public static function sizeForObject(ObjectEntry $object): int|false
    {
        $host = self::hostForObject($object);
        if (null !== $host) {
            return self::size($host);
        }
        $seg = self::jitOwnedForLookupKey($object->id);

        return null === $seg ? false : $seg['size'];
    }

    public static function close(object $host, ?ObjectEntry $object = null): void
    {
        if (self::hostAvailable()) {
            @\shmop_close($host);
        }
        if (null !== $object) {
            self::detachObject($object);
            self::releaseJitOwned($object->id);
        }
    }

    public static function closeForObject(ObjectEntry $object): void
    {
        $host = self::hostForObject($object);
        if (null !== $host) {
            self::close($host, $object);

            return;
        }
        // php-src shmop_close is a NOP; detach on object free. Keep map for delete/size until GC.
    }

    public static function delete(object $host): bool
    {
        if (!self::hostAvailable()) {
            return false;
        }

        return @\shmop_delete($host);
    }

    public static function deleteForObject(ObjectEntry $object): bool
    {
        $host = self::hostForObject($object);
        if (null !== $host) {
            return self::delete($host);
        }
        $seg = self::jitOwnedForLookupKey($object->id);
        if (null === $seg) {
            return false;
        }

        return 0 === ShmopLibcThinAbi::shmctlRmid($seg['shmid']);
    }

    public static function deleteForLookupKey(int $key): bool
    {
        $seg = self::jitOwnedForLookupKey($key);
        if (null === $seg) {
            return false;
        }

        return 0 === ShmopLibcThinAbi::shmctlRmid($seg['shmid']);
    }
}
