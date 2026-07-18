<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PgSql\Connection object + connection state (php-src ext/pgsql; #3741).
 */
final class VmPgsqlConnection
{
    public const CLASS_LC = 'pgsql\\connection';

    public const CLASS_NAME = 'PgSql\\Connection';

    /** @var array<int, array{native: \FFI\CData, closed: bool}> */
    private static array $state = [];

    private static string $lastError = '';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function setLastError(string $message): void
    {
        self::$lastError = $message;
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function wrap(\FFI\CData $native, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'native' => $native,
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

    public static function native(ObjectEntry $object): \FFI\CData
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \TypeError('pg_*(): supplied resource is not a valid PostgreSQL link resource');
        }

        return self::$state[$object->id]['native'];
    }

    public static function close(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        VmPgsqlNative::finish(self::$state[$object->id]['native']);
        self::$state[$object->id]['closed'] = true;

        return true;
    }
}
