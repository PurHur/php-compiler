<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlsrv;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Sqlsrv\Connection resource object (php-src ext/sqlsrv; #6577).
 */
final class VmSqlsrvConnection
{
    public const CLASS_LC = 'sqlsrv\\connection';

    public const CLASS_NAME = 'Sqlsrv\\Connection';

    /** @var array<int, array{closed: bool, native: mixed}> */
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

    /**
     * @param mixed $native host sqlsrv resource when bridged
     */
    public static function wrap(mixed $native, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'closed' => false,
            'native' => $native,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function native(ObjectEntry $object): mixed
    {
        return self::$state[$object->id]['native'] ?? null;
    }

    public static function close(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        $native = self::$state[$object->id]['native'];
        if (SqlsrvExtensionPolicy::hasNativeDriver() && \is_resource($native)) {
            \sqlsrv_close($native);
        }
        self::$state[$object->id]['closed'] = true;
        unset(self::$state[$object->id]);

        return true;
    }

    public static function requireLive(ObjectEntry $object, string $fn): ObjectEntry
    {
        if (!self::isLive($object)) {
            throw new \TypeError($fn.'(): supplied resource is not a valid sqlsrv connection resource');
        }

        return $object;
    }
}
