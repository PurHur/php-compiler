<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * System V shared memory — host sysvshm delegation (php-src ext/sysvshm/sysvshm.c; #6436).
 *
 * PHP-in-PHP: SysvSharedMemory VM objects map to host {@see \SysvSharedMemory} handles.
 */
final class VmSysvShm
{
    public const CLASS_LC = 'sysvsharedmemory';

    /** @var array<int, object> VM object id => host SysvSharedMemory */
    private static array $hostByObjectId = [];

    public static function available(): bool
    {
        return \function_exists('shm_attach');
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('SysvSharedMemory');
        $entry->isInternal = true;
        // php-src `final class SysvSharedMemory` (ext/sysvshm/sysvshm.stub.php; #28422).
        $entry->isFinal = true;
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
        if (0 !== strcasecmp($object->class->name, 'SysvSharedMemory')) {
            return null;
        }

        return self::$hostByObjectId[$object->id] ?? null;
    }

    public static function detachObject(ObjectEntry $object): void
    {
        unset(self::$hostByObjectId[$object->id]);
    }

    public static function isSysvSharedMemoryObject(?ObjectEntry $object): bool
    {
        return null !== $object && 0 === strcasecmp($object->class->name, 'SysvSharedMemory');
    }

    /**
     * @return array{0: Variable|false, 1: int, 2: string}
     */
    public static function attach(Context $ctx, int $key, ?int $memsize, ?int $perm): array
    {
        if (!self::available()) {
            return [false, 0, 'shm_attach() is not available in this compiler build'];
        }

        $errno = 0;
        $errstr = '';
        if (null === $memsize && null === $perm) {
            $host = @\shm_attach($key);
        } elseif (null === $perm) {
            $host = @\shm_attach($key, $memsize);
        } else {
            $host = @\shm_attach($key, $memsize, $perm);
        }

        if (false === $host || !\is_object($host)) {
            $last = \error_get_last();
            $message = \is_array($last) && isset($last['message']) ? (string) $last['message'] : 'shm_attach() failed';

            return [false, $errno, $message];
        }

        return [self::wrapHost($ctx, $host), 0, ''];
    }

    /**
     * @return mixed|false
     */
    public static function getVar(object $host, int $key): mixed
    {
        if (!self::available()) {
            return false;
        }

        return @\shm_get_var($host, $key);
    }

    public static function putVar(object $host, int $key, mixed $value): bool
    {
        if (!self::available()) {
            return false;
        }

        return @\shm_put_var($host, $key, $value);
    }

    public static function removeVar(object $host, int $key): bool
    {
        if (!self::available()) {
            return false;
        }

        return @\shm_remove_var($host, $key);
    }

    /** shm_has_var() — whether variable key exists in segment (#21634). */
    public static function hasVar(object $host, int $key): bool
    {
        if (!self::available() || !\function_exists('shm_has_var')) {
            return false;
        }

        return @\shm_has_var($host, $key);
    }

    /**
     * shm_remove() — destroy SysV shared memory segment (IPC_RMID; #21635).
     *
     * php-src leaves the SysvSharedMemory object usable for detach after remove;
     * do not clear the host map here.
     */
    public static function remove(object $host): bool
    {
        if (!self::available() || !\function_exists('shm_remove')) {
            return false;
        }

        return @\shm_remove($host);
    }

    public static function detach(object $host, ?ObjectEntry $object = null): bool
    {
        if (!self::available()) {
            return false;
        }

        $result = @\shm_detach($host);
        if (null !== $object) {
            self::detachObject($object);
        }

        return $result;
    }
}
