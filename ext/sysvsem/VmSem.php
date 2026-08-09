<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * System V semaphores — host sysvsem delegation (php-src ext/sysvsem/sysvsem.c; #3704).
 *
 * PHP-in-PHP: SysvSemaphore VM objects map to host {@see \SysvSemaphore} handles.
 */
final class VmSem
{
    public const CLASS_LC = 'sysvsemaphore';

    /** @var array<int, object> VM object id => host SysvSemaphore */
    private static array $hostByObjectId = [];

    public static function available(): bool
    {
        return \function_exists('sem_get');
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('SysvSemaphore');
        $entry->isInternal = true;
        // php-src `final class SysvSemaphore` (ext/sysvsem/sysvsem.stub.php; #28422).
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
        if (0 !== strcasecmp($object->class->name, 'SysvSemaphore')) {
            return null;
        }

        return self::$hostByObjectId[$object->id] ?? null;
    }

    public static function detachObject(ObjectEntry $object): void
    {
        unset(self::$hostByObjectId[$object->id]);
    }

    public static function isSysvSemaphoreObject(?ObjectEntry $object): bool
    {
        return null !== $object && 0 === strcasecmp($object->class->name, 'SysvSemaphore');
    }

    /**
     * @return array{0: Variable|false, 1: string}
     */
    public static function get(Context $ctx, int $key, ?int $maxAcquire, ?int $perm, ?bool $autoRelease): array
    {
        if (!self::available()) {
            return [false, 'sem_get() is not available in this compiler build'];
        }

        if (null === $maxAcquire && null === $perm && null === $autoRelease) {
            $host = @\sem_get($key);
        } elseif (null === $perm && null === $autoRelease) {
            $host = @\sem_get($key, $maxAcquire);
        } elseif (null === $autoRelease) {
            $host = @\sem_get($key, $maxAcquire, $perm);
        } else {
            // Host stub is bool $auto_release; pass bool under strict_types (#19515).
            $host = @\sem_get($key, $maxAcquire, $perm, $autoRelease);
        }

        if (false === $host || !\is_object($host)) {
            $last = \error_get_last();
            $message = \is_array($last) && isset($last['message']) ? (string) $last['message'] : 'sem_get() failed';

            return [false, $message];
        }

        return [self::wrapHost($ctx, $host), ''];
    }

    public static function acquire(object $host, bool $nowait = false): bool
    {
        if (!self::available()) {
            return false;
        }

        return @\sem_acquire($host, $nowait);
    }

    public static function release(object $host): bool
    {
        if (!self::available()) {
            return false;
        }

        return @\sem_release($host);
    }

    public static function remove(object $host): bool
    {
        if (!self::available()) {
            return false;
        }

        return @\sem_remove($host);
    }
}
