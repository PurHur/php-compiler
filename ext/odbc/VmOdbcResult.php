<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Odbc\Result object (php-src ext/odbc; #6293 Phase 1 stub).
 */
final class VmOdbcResult
{
    public const CLASS_LC = 'odbc\\result';

    public const CLASS_NAME = 'Odbc\\Result';

    /** @var array<int, array{rows: list<list<mixed>>, cursor: int, closed: bool, connection: ObjectEntry}> */
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
     * @param list<list<mixed>> $rows
     */
    public static function wrap(array $rows, ObjectEntry $connection, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'rows' => $rows,
            'cursor' => -1,
            'closed' => false,
            'connection' => $connection,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function requireLive(ObjectEntry $object): void
    {
        if (!self::isLive($object)) {
            throw new \TypeError('odbc_*(): supplied resource is not a valid ODBC result resource');
        }
    }

    public static function fetchRow(ObjectEntry $object, ?int $rowNumber): bool
    {
        self::requireLive($object);
        $row = &self::$state[$object->id];
        if (null !== $rowNumber) {
            $idx = $rowNumber - 1;
            if ($idx < 0 || $idx >= \count($row['rows'])) {
                return false;
            }
            $row['cursor'] = $idx;

            return true;
        }
        $next = $row['cursor'] + 1;
        if ($next >= \count($row['rows'])) {
            return false;
        }
        $row['cursor'] = $next;

        return true;
    }

    /**
     * @return mixed
     */
    public static function field(ObjectEntry $object, int|string $field)
    {
        self::requireLive($object);
        $row = self::$state[$object->id];
        if ($row['cursor'] < 0 || $row['cursor'] >= \count($row['rows'])) {
            return false;
        }
        $current = $row['rows'][$row['cursor']];
        if (\is_int($field)) {
            $idx = $field - 1;
            if ($idx < 0 || $idx >= \count($current)) {
                return false;
            }

            return $current[$idx];
        }
        if (\ctype_digit($field)) {
            return self::field($object, (int) $field);
        }

        return false;
    }

    public static function numRows(ObjectEntry $object): int
    {
        self::requireLive($object);

        return \count(self::$state[$object->id]['rows']);
    }

    public static function free(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        self::$state[$object->id]['closed'] = true;
        self::$state[$object->id]['rows'] = [];

        return true;
    }
}
