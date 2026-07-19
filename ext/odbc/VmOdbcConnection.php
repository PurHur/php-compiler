<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Odbc\Connection object + last error state (php-src ext/odbc; #6293).
 */
final class VmOdbcConnection
{
    public const CLASS_LC = 'odbc\\connection';

    public const CLASS_NAME = 'Odbc\\Connection';

    /** @var array<int, array{henv: ?\FFI\CData, hdbc: ?\FFI\CData, closed: bool, object: ObjectEntry}> */
    private static array $state = [];

    private static string $lastState = '';

    private static string $lastErrorMsg = '';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function setLastError(string $state, string $message): void
    {
        self::$lastState = $state;
        self::$lastErrorMsg = $message;
    }

    public static function lastState(): string
    {
        return self::$lastState;
    }

    public static function lastErrorMsg(): string
    {
        return self::$lastErrorMsg;
    }

    /**
     * @param array{henv: \FFI\CData, hdbc: \FFI\CData} $native
     */
    public static function wrap(array $native, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'henv' => $native['henv'],
            'hdbc' => $native['hdbc'],
            'closed' => false,
            'object' => $object,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    /**
     * @return array{henv: ?\FFI\CData, hdbc: ?\FFI\CData}
     */
    public static function native(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \TypeError('odbc_*(): supplied resource is not a valid ODBC connection resource');
        }

        return [
            'henv' => self::$state[$object->id]['henv'],
            'hdbc' => self::$state[$object->id]['hdbc'],
        ];
    }

    public static function close(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        $row = self::$state[$object->id];
        VmOdbcNative::disconnect($row['henv'], $row['hdbc']);
        self::$state[$object->id]['closed'] = true;
        self::$state[$object->id]['henv'] = null;
        self::$state[$object->id]['hdbc'] = null;

        return true;
    }

    public static function closeAll(): void
    {
        foreach (self::$state as $id => $row) {
            if ($row['closed']) {
                continue;
            }
            VmOdbcNative::disconnect($row['henv'], $row['hdbc']);
            self::$state[$id]['closed'] = true;
            self::$state[$id]['henv'] = null;
            self::$state[$id]['hdbc'] = null;
        }
    }
}
