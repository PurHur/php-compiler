<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\sqlite3\VmSqlite3Native;

/**
 * PDOStatement VM class (php-src ext/pdo/pdo_stmt.c; #3367).
 *
 * Implements Iterator so `foreach ($pdo->query(...))` yields associative rows.
 */
final class VmPDOStatement
{
    public const CLASS_LC = 'pdostatement';

    /** @var array<int, PdoStatementState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['execute'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('PDOStatement');
        $entry->isInternal = true;
        foreach (['Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        foreach ([
            'execute' => new PDOStatementExecute(),
            'fetch' => new PDOStatementFetch(),
            'fetchall' => new PDOStatementFetchAll(),
            'fetchcolumn' => new PDOStatementFetchColumn(),
            'fetchobject' => new PDOStatementFetchObject(),
            'bindvalue' => new PDOStatementBindValue(),
            'rowcount' => new PDOStatementRowCount(),
            'columncount' => new PDOStatementColumnCount(),
            'closecursor' => new PDOStatementCloseCursor(),
            'rewind' => new PDOStatementRewind(),
            'valid' => new PDOStatementValid(),
            'current' => new PDOStatementCurrent(),
            'key' => new PDOStatementKey(),
            'next' => new PDOStatementNext(),
        ] as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['fetchall'] = 'fetchAll';
        $entry->methodNames['fetchcolumn'] = 'fetchColumn';
        $entry->methodNames['fetchobject'] = 'fetchObject';
        $entry->methodNames['bindvalue'] = 'bindValue';
        $entry->methodNames['rowcount'] = 'rowCount';
        $entry->methodNames['columncount'] = 'columnCount';
        $entry->methodNames['closecursor'] = 'closeCursor';

        self::$classEntry = $entry;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param \FFI\CData $stmt sqlite3_stmt*
     */
    public static function create(ObjectEntry $pdo, $stmt, string $sql, bool $executed, int $rowCount = 0): ObjectEntry
    {
        if (null === self::$classEntry) {
            throw new \LogicException('PDOStatement class not registered');
        }
        $entry = new ObjectEntry(self::$classEntry);
        $state = new PdoStatementState();
        $state->pdoId = $pdo->id;
        $state->stmt = $stmt;
        $state->sql = $sql;
        $state->executed = $executed;
        $state->rowCount = $rowCount;
        $state->fetchMode = VmPDO::state($pdo)->fetchMode;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    /**
     * php-src pdo_sqlite execute: SQLITE_DONE sets row_count from sqlite3_changes();
     * SQLITE_ROW (SELECT with data) leaves row_count at 0.
     *
     * @param \FFI\CData $stmt sqlite3_stmt*
     * @param \FFI\CData $db sqlite3*
     */
    public static function rowCountAfterStep($stmt, $db, int $stepRc): int
    {
        if (VmSqlite3Native::STEP_DONE === $stepRc) {
            return VmSqlite3Native::changes($db);
        }

        return 0;
    }

    private static ?ClassEntry $classEntry = null;

    public static function setClassEntry(ClassEntry $entry): void
    {
        self::$classEntry = $entry;
    }

    public static function state(ObjectEntry $entry): PdoStatementState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('PDOStatement object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    /** @return array<string|int, mixed>|false */
    public static function fetchRow(PdoStatementState $st, int $mode): array|false
    {
        if (null === $st->stmt) {
            return false;
        }
        if (!$st->executed) {
            return false;
        }
        $rc = VmSqlite3Native::step($st->stmt);
        if (VmSqlite3Native::STEP_ROW !== $rc) {
            $st->exhausted = true;
            $st->current = null;

            return false;
        }
        $count = VmSqlite3Native::columnCount($st->stmt);
        $assoc = [];
        $num = [];
        for ($i = 0; $i < $count; ++$i) {
            $name = VmSqlite3Native::columnName($st->stmt, $i);
            $value = VmSqlite3Native::columnValueAt($st->stmt, $i);
            $assoc[$name] = $value;
            $num[$i] = $value;
        }
        $row = match ($mode) {
            PdoConstants::FETCH_ASSOC => $assoc,
            PdoConstants::FETCH_NUM => $num,
            default => $assoc + $num,
        };
        $st->current = $row;
        ++$st->key;

        return $row;
    }
}

/** @internal */
final class PdoStatementState
{
    public int $pdoId = 0;

    /** @var \FFI\CData|null sqlite3_stmt* */
    public $stmt = null;

    public string $sql = '';

    public bool $executed = false;

    public bool $exhausted = false;

    public int $fetchMode = PdoConstants::FETCH_BOTH;

    /** Rows affected by last DML execute (php-src stmt->row_count / sqlite3_changes). */
    public int $rowCount = 0;

    public int $key = -1;

    /** @var array<string|int, mixed>|null */
    public ?array $current = null;

    /** @var list<mixed> */
    public array $bound = [];
}

final class PDOStatementExecute extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('execute');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::execute()');
        if (VmPDOStatement::CLASS_LC !== strtolower($receiver->class->name)) {
            throw new \TypeError('PDOStatement::execute() must be called on PDOStatement');
        }
        $st = VmPDOStatement::state($receiver);
        $pdoState = VmPDO::stateById($st->pdoId);
        if (null === $st->stmt) {
            VmPDO::raise($pdoState, 'PDOStatement is not initialized');
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        try {
            VmSqlite3Native::reset($st->stmt);
            VmSqlite3Native::clearBindings($st->stmt);
            $params = [];
            if (\count($frame->calledArgs) >= 2) {
                $arg = $frame->calledArgs[1]->resolveIndirect();
                if (Variable::TYPE_ARRAY === $arg->type) {
                    foreach ($arg->toArray()->iterate() as $slot) {
                        $params[] = VmPDO::phpValueFromVariable($slot);
                    }
                }
            }
            foreach ($st->bound as $index => $value) {
                $params[$index] = $value;
            }
            $i = 1;
            foreach ($params as $value) {
                VmSqlite3Native::bindValue($st->stmt, $i, $value);
                ++$i;
            }
            $rc = VmSqlite3Native::step($st->stmt);
            if (VmSqlite3Native::STEP_ROW !== $rc && VmSqlite3Native::STEP_DONE !== $rc) {
                $pdoState = VmPDO::stateById($st->pdoId);
                $msg = 'SQL execution failed';
                // Prefer DB errmsg when available via parent.
                VmPDO::raise($pdoState, $msg);
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $db = $pdoState->db;
            if (null === $db) {
                throw new \LogicException('PDO object has not been correctly initialized by its constructor');
            }
            $st->rowCount = VmPDOStatement::rowCountAfterStep($st->stmt, $db, $rc);
            // Leave cursor at start for subsequent fetch/foreach (SELECT).
            VmSqlite3Native::reset($st->stmt);
            $st->executed = true;
            $st->exhausted = false;
            $st->key = -1;
            $st->current = null;
        } catch (\SQLite3Exception $e) {
            VmPDO::raise($pdoState, $e->getMessage());
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class PDOStatementFetch extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::fetch()');
        $st = VmPDOStatement::state($receiver);
        $mode = $st->fetchMode;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'PDOStatement::fetch', 0, 'mode');
        }
        $row = VmPDOStatement::fetchRow($st, $mode);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        VmPDO::assignRow($frame->returnVar, $row);
    }
}

final class PDOStatementFetchAll extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('fetchAll');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::fetchAll()');
        $st = VmPDOStatement::state($receiver);
        $mode = $st->fetchMode;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'PDOStatement::fetchAll', 0, 'mode');
        }
        $ht = new HashTable();
        $i = 0;
        while (false !== ($row = VmPDOStatement::fetchRow($st, $mode))) {
            $slot = new Variable();
            VmPDO::assignRow($slot, $row);
            $ht->add((string) $i, $slot);
            ++$i;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($ht);
        }
    }
}

final class PDOStatementBindValue extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('bindValue');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::bindValue()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'PDOStatement::bindValue() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $st = VmPDOStatement::state($receiver);
        $param = $this->intArg($frame->calledArgs[1], 'PDOStatement::bindValue', 0, 'param');
        $value = VmPDO::phpValueFromVariable($frame->calledArgs[2]);
        // 1-based param index → 0-based sparse list storage offset param-1
        $st->bound[$param - 1] = $value;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/** php-src zim_PDOStatement_fetchColumn — next row, return column $column (default 0). */
final class PDOStatementFetchColumn extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('fetchColumn');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::fetchColumn()');
        $st = VmPDOStatement::state($receiver);
        $column = 0;
        if (\count($frame->calledArgs) >= 2) {
            $column = $this->intArg($frame->calledArgs[1], 'PDOStatement::fetchColumn', 0, 'column');
        }
        $row = VmPDOStatement::fetchRow($st, PdoConstants::FETCH_NUM);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $row || !\array_key_exists($column, $row)) {
            $frame->returnVar->bool(false);

            return;
        }
        VmPDO::assignScalar($frame->returnVar, $row[$column]);
    }
}

/**
 * php-src zim_PDOStatement_fetchObject — FETCH_CLASS into stdClass (custom class args deferred).
 */
final class PDOStatementFetchObject extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('fetchObject');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::fetchObject()');
        $st = VmPDOStatement::state($receiver);
        $className = 'stdClass';
        if (\count($frame->calledArgs) >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $className = $this->stringArg($frame->calledArgs[1], 'PDOStatement::fetchObject', 0, 'class');
            }
        }
        if ('stdclass' !== strtolower($className)) {
            throw new \LogicException(
                'PDOStatement::fetchObject() custom class is not supported in this compiler build'
            );
        }
        $row = VmPDOStatement::fetchRow($st, PdoConstants::FETCH_ASSOC);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx || !isset($ctx->classes['stdclass'])) {
            throw new \LogicException('stdClass is not registered');
        }
        $object = new ObjectEntry($ctx->classes['stdclass']);
        $object->constructed = true;
        foreach ($row as $key => $value) {
            $slot = $object->allocateProperty((string) $key);
            VmPDO::assignScalar($slot, $value);
        }
        $frame->returnVar->object($object);
    }
}

/** php-src zim_PDOStatement_rowCount — stmt->row_count (sqlite3_changes on DML DONE). */
final class PDOStatementRowCount extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('rowCount');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::rowCount()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmPDOStatement::state($receiver)->rowCount);
        }
    }
}

/** php-src zim_PDOStatement_columnCount — number of result columns. */
final class PDOStatementColumnCount extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('columnCount');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::columnCount()');
        $st = VmPDOStatement::state($receiver);
        $count = 0;
        if (null !== $st->stmt) {
            $count = VmSqlite3Native::columnCount($st->stmt);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}

/**
 * php-src zim_PDOStatement_closeCursor + pdo_sqlite_stmt_cursor_closer —
 * sqlite3_reset; stmt->executed = 0.
 */
final class PDOStatementCloseCursor extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('closeCursor');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::closeCursor()');
        $st = VmPDOStatement::state($receiver);
        if (null !== $st->stmt) {
            VmSqlite3Native::reset($st->stmt);
        }
        $st->executed = false;
        $st->exhausted = false;
        $st->key = -1;
        $st->current = null;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class PDOStatementRewind extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::rewind()');
        $st = VmPDOStatement::state($receiver);
        if (null !== $st->stmt && $st->executed) {
            VmSqlite3Native::reset($st->stmt);
            $st->exhausted = false;
            $st->key = -1;
            $st->current = null;
            VmPDOStatement::fetchRow($st, PdoConstants::FETCH_ASSOC);
        }
    }
}

final class PDOStatementValid extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::valid()');
        $st = VmPDOStatement::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(null !== $st->current && !$st->exhausted);
        }
    }
}

final class PDOStatementCurrent extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::current()');
        $st = VmPDOStatement::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $st->current) {
            $frame->returnVar->null();

            return;
        }
        VmPDO::assignRow($frame->returnVar, $st->current);
    }
}

final class PDOStatementKey extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::key()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmPDOStatement::state($receiver)->key);
        }
    }
}

final class PDOStatementNext extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::next()');
        $st = VmPDOStatement::state($receiver);
        VmPDOStatement::fetchRow($st, PdoConstants::FETCH_ASSOC);
    }
}
