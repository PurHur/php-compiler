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

    /** @var array<int, array{native: \FFI\CData, closed: bool, trace_fp: ?\FFI\CData, object: ObjectEntry}> */
    private static array $state = [];

    private static string $lastError = '';

    /** Default link id for optional-connection builtins (php-src FETCH_DEFAULT_LINK; #20574). */
    private static ?int $defaultLinkId = null;

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
            'trace_fp' => null,
            'object' => $object,
        ];
        self::$defaultLinkId = $object->id;
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

    /**
     * Resolve optional connection arg or default link (php-src FETCH_DEFAULT_LINK; #20574).
     */
    public static function resolveOptionalConnection(?ObjectEntry $provided): ?ObjectEntry
    {
        if (null !== $provided) {
            return $provided;
        }
        if (null === self::$defaultLinkId) {
            return null;
        }
        $row = self::$state[self::$defaultLinkId] ?? null;
        if (null === $row || $row['closed']) {
            return null;
        }

        return $row['object'];
    }

    /**
     * Optional connection or default link; warn when none (php-src CHECK_DEFAULT_LINK; #20680).
     */
    public static function requireOptionalOrDefault(?ObjectEntry $provided, string $functionName): ?ObjectEntry
    {
        $connObj = self::resolveOptionalConnection($provided);
        if (null === $connObj) {
            @\trigger_error($functionName.'(): No PostgreSQL connection opened yet', \E_USER_WARNING);
        }

        return $connObj;
    }

    public static function setTraceFp(ObjectEntry $object, ?\FFI\CData $fp): void
    {
        if (!isset(self::$state[$object->id])) {
            return;
        }
        $prev = self::$state[$object->id]['trace_fp'];
        if (null !== $prev && $prev !== $fp) {
            VmPgsqlNative::fclose($prev);
        }
        self::$state[$object->id]['trace_fp'] = $fp;
    }

    public static function clearTraceFp(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id])) {
            return;
        }
        $fp = self::$state[$object->id]['trace_fp'];
        if (null !== $fp) {
            VmPgsqlNative::fclose($fp);
            self::$state[$object->id]['trace_fp'] = null;
        }
    }

    public static function close(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        self::clearTraceFp($object);
        VmPgsqlNative::finish(self::$state[$object->id]['native']);
        self::$state[$object->id]['closed'] = true;
        if (self::$defaultLinkId === $object->id) {
            self::$defaultLinkId = null;
        }

        return true;
    }
}
