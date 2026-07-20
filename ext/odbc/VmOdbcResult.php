<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Odbc\Result object (php-src ext/odbc; #6293 / #21258).
 *
 * Rows are buffered after execute/exec/tables/columns. Prepared statements keep
 * an hstmt until free_result / GC.
 */
final class VmOdbcResult
{
    public const CLASS_LC = 'odbc\\result';

    public const CLASS_NAME = 'Odbc\\Result';

    /**
     * @var array<int, array{
     *   rows: list<list<mixed>>,
     *   colnames: list<string>,
     *   coltypes: list<string>,
     *   collens: list<int>,
     *   cursor: int,
     *   closed: bool,
     *   connection: ObjectEntry,
     *   hstmt: ?\FFI\CData,
     *   numparams: int,
     *   executed: bool,
     *   binds: list<mixed>,
     *   binmode: int,
     *   longreadlen: int
     * }>
     */
    private static array $state = [];

    /** php.ini odbc.defaultbinmode default. */
    public const DEFAULT_BINMODE = 1;

    /** php.ini odbc.defaultlrl default. */
    public const DEFAULT_LONGREADLEN = 4096;

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
     * @param list<string>      $colnames
     * @param list<string>      $coltypes
     * @param list<int>         $collens
     * @param list<mixed>       $binds
     */
    public static function wrap(
        array $rows,
        ObjectEntry $connection,
        Context $ctx,
        ?\FFI\CData $hstmt = null,
        array $colnames = [],
        array $coltypes = [],
        array $collens = [],
        int $numparams = 0,
        bool $executed = true,
        array $binds = []
    ): Variable {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        if ([] === $colnames && [] !== $rows && \is_array($rows[0] ?? null)) {
            $n = \count($rows[0]);
            for ($i = 0; $i < $n; ++$i) {
                $colnames[] = (string) ($i + 1);
                $coltypes[] = '';
                $collens[] = 0;
            }
        }
        self::$state[$object->id] = [
            'rows' => $rows,
            'colnames' => $colnames,
            'coltypes' => $coltypes,
            'collens' => $collens,
            'cursor' => -1,
            'closed' => false,
            'connection' => $connection,
            'hstmt' => $hstmt,
            'numparams' => $numparams,
            'executed' => $executed,
            'binds' => $binds,
            'binmode' => self::DEFAULT_BINMODE,
            'longreadlen' => self::DEFAULT_LONGREADLEN,
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
     * @return list<mixed>|false
     */
    public static function currentRowValues(ObjectEntry $object): array|false
    {
        self::requireLive($object);
        $row = self::$state[$object->id];
        if ($row['cursor'] < 0 || $row['cursor'] >= \count($row['rows'])) {
            return false;
        }

        return $row['rows'][$row['cursor']];
    }

    /**
     * @return list<string>
     */
    public static function colnames(ObjectEntry $object): array
    {
        self::requireLive($object);

        return self::$state[$object->id]['colnames'];
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
        foreach ($row['colnames'] as $i => $name) {
            if (0 === \strcasecmp($name, $field)) {
                return $current[$i] ?? false;
            }
        }

        return false;
    }

    public static function numRows(ObjectEntry $object): int
    {
        self::requireLive($object);

        return \count(self::$state[$object->id]['rows']);
    }

    public static function numFields(ObjectEntry $object): int
    {
        self::requireLive($object);

        return \count(self::$state[$object->id]['colnames']);
    }

    public static function fieldName(ObjectEntry $object, int $field1Based): string|false
    {
        self::requireLive($object);
        if ($field1Based < 1) {
            throw new \ValueError('odbc_field_name(): Argument #2 ($field) must be greater than 0');
        }
        $cols = self::$state[$object->id]['colnames'];
        if (0 === \count($cols)) {
            return false;
        }
        if ($field1Based > \count($cols)) {
            return false;
        }

        return $cols[$field1Based - 1];
    }

    public static function fieldType(ObjectEntry $object, int $field1Based): string|false
    {
        self::requireLive($object);
        if ($field1Based < 1) {
            throw new \ValueError('odbc_field_type(): Argument #2 ($field) must be greater than 0');
        }
        $types = self::$state[$object->id]['coltypes'];
        if (0 === \count($types)) {
            return false;
        }
        if ($field1Based > \count($types)) {
            return false;
        }

        return $types[$field1Based - 1];
    }

    public static function fieldLen(ObjectEntry $object, int $field1Based): int|false
    {
        self::requireLive($object);
        if ($field1Based < 1) {
            throw new \ValueError('odbc_field_len(): Argument #2 ($field) must be greater than 0');
        }
        $lens = self::$state[$object->id]['collens'];
        if (0 === \count($lens)) {
            return false;
        }
        if ($field1Based > \count($lens)) {
            return false;
        }

        return $lens[$field1Based - 1];
    }

    public static function fieldNum(ObjectEntry $object, string $name): int|false
    {
        self::requireLive($object);
        foreach (self::$state[$object->id]['colnames'] as $i => $col) {
            if (0 === \strcasecmp($col, $name)) {
                return $i + 1;
            }
        }

        return false;
    }

    public static function numParams(ObjectEntry $object): int
    {
        self::requireLive($object);

        return self::$state[$object->id]['numparams'];
    }

    /**
     * @return \FFI\CData|null
     */
    public static function hstmt(ObjectEntry $object): ?\FFI\CData
    {
        self::requireLive($object);

        return self::$state[$object->id]['hstmt'];
    }

    public static function connection(ObjectEntry $object): ObjectEntry
    {
        self::requireLive($object);

        return self::$state[$object->id]['connection'];
    }

    public static function setBinmode(ObjectEntry $object, int $mode): void
    {
        self::requireLive($object);
        self::$state[$object->id]['binmode'] = $mode;
    }

    public static function setLongreadlen(ObjectEntry $object, int $length): void
    {
        self::requireLive($object);
        self::$state[$object->id]['longreadlen'] = $length;
    }

    public static function binmode(ObjectEntry $object): int
    {
        self::requireLive($object);

        return self::$state[$object->id]['binmode'];
    }

    public static function longreadlen(ObjectEntry $object): int
    {
        self::requireLive($object);

        return self::$state[$object->id]['longreadlen'];
    }

    public static function setNumParams(ObjectEntry $object, int $n): void
    {
        self::requireLive($object);
        self::$state[$object->id]['numparams'] = $n;
    }

    /**
     * @param list<list<mixed>> $rows
     * @param list<string>      $colnames
     * @param list<string>      $coltypes
     * @param list<int>         $collens
     * @param list<mixed>       $binds
     */
    public static function applyBuffered(
        ObjectEntry $object,
        array $rows,
        array $colnames,
        array $coltypes,
        array $collens,
        array $binds = []
    ): void {
        self::requireLive($object);
        self::$state[$object->id]['rows'] = $rows;
        self::$state[$object->id]['colnames'] = $colnames;
        self::$state[$object->id]['coltypes'] = $coltypes;
        self::$state[$object->id]['collens'] = $collens;
        self::$state[$object->id]['cursor'] = -1;
        self::$state[$object->id]['executed'] = true;
        self::$state[$object->id]['binds'] = $binds;
    }

    public static function free(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return true;
        }
        VmOdbcNative::freeStmt(self::$state[$object->id]['hstmt']);
        self::$state[$object->id]['closed'] = true;
        self::$state[$object->id]['rows'] = [];
        self::$state[$object->id]['hstmt'] = null;
        self::$state[$object->id]['binds'] = [];

        return true;
    }
}
