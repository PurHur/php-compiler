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
 */
final class VmShmop
{
    public const CLASS_LC = 'shmop';

    /** @var array<int, object> VM object id => host Shmop */
    private static array $hostByObjectId = [];

    public static function available(): bool
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
        if (!self::available()) {
            return [false, 'shmop_open() is not available in this compiler build'];
        }

        $host = @\shmop_open($key, $mode, $permissions, $size);
        if (false === $host || !\is_object($host)) {
            $last = \error_get_last();
            $message = \is_array($last) && isset($last['message']) ? (string) $last['message'] : 'shmop_open() failed';

            return [false, $message];
        }

        return [self::wrapHost($ctx, $host), ''];
    }

    /**
     * @return string|false
     */
    public static function read(object $host, int $start, int $count): string|false
    {
        if (!self::available()) {
            return false;
        }

        return @\shmop_read($host, $start, $count);
    }

    public static function write(object $host, string $data, int $offset): int|false
    {
        if (!self::available()) {
            return false;
        }

        return @\shmop_write($host, $data, $offset);
    }

    public static function size(object $host): int|false
    {
        if (!self::available()) {
            return false;
        }

        return @\shmop_size($host);
    }

    public static function close(object $host, ?ObjectEntry $object = null): void
    {
        if (!self::available()) {
            return;
        }

        @\shmop_close($host);
    }

    public static function delete(object $host): bool
    {
        if (!self::available()) {
            return false;
        }

        return @\shmop_delete($host);
    }
}
