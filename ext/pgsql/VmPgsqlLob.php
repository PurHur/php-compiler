<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PgSql\Lob large-object handle (php-src ext/pgsql; #20587).
 */
final class VmPgsqlLob
{
    public const CLASS_LC = 'pgsql\\lob';

    public const CLASS_NAME = 'PgSql\\Lob';

    /** @var array<int, array{conn: ObjectEntry, fd: int, closed: bool}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrap(ObjectEntry $connection, int $fd, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'conn' => $connection,
            'fd' => $fd,
            'closed' => false,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function connection(ObjectEntry $object): ObjectEntry
    {
        if (!self::isLive($object)) {
            throw new \TypeError('pg_*(): supplied resource is not a valid PostgreSQL large object');
        }

        return self::$state[$object->id]['conn'];
    }

    public static function fd(ObjectEntry $object): int
    {
        if (!self::isLive($object)) {
            throw new \TypeError('pg_*(): supplied resource is not a valid PostgreSQL large object');
        }

        return self::$state[$object->id]['fd'];
    }

    public static function markClosed(ObjectEntry $object): void
    {
        if (isset(self::$state[$object->id])) {
            self::$state[$object->id]['closed'] = true;
        }
    }
}
