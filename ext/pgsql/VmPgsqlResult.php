<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PgSql\Result object + result state (php-src ext/pgsql; #3741).
 */
final class VmPgsqlResult
{
    public const CLASS_LC = 'pgsql\\result';

    public const CLASS_NAME = 'PgSql\\Result';

    /** @var array<int, array{native: \FFI\CData, closed: bool, row: int, conn: ?ObjectEntry}> */
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

    public static function wrap(\FFI\CData $native, Context $ctx, ?ObjectEntry $connection = null): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'native' => $native,
            'closed' => false,
            'row' => 0,
            'conn' => $connection,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function native(ObjectEntry $object): \FFI\CData
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \TypeError('pg_*(): supplied resource is not a valid PostgreSQL result resource');
        }

        return self::$state[$object->id]['native'];
    }

    public static function connection(ObjectEntry $object): ?ObjectEntry
    {
        return self::$state[$object->id]['conn'] ?? null;
    }

    public static function currentRow(ObjectEntry $object): int
    {
        return self::$state[$object->id]['row'] ?? 0;
    }

    public static function setCurrentRow(ObjectEntry $object, int $row): void
    {
        if (isset(self::$state[$object->id])) {
            self::$state[$object->id]['row'] = $row;
        }
    }

    public static function advanceRow(ObjectEntry $object): void
    {
        if (isset(self::$state[$object->id])) {
            ++self::$state[$object->id]['row'];
        }
    }

    public static function clear(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return;
        }
        VmPgsqlNative::clear(self::$state[$object->id]['native']);
        self::$state[$object->id]['closed'] = true;
    }
}
