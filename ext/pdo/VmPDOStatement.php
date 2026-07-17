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
            'bindvalue' => new PDOStatementBindValue(),
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
        $entry->methodNames['bindvalue'] = 'bindValue';

        self::$classEntry = $entry;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param \FFI\CData $stmt sqlite3_stmt*
     */
    public static function create(ObjectEntry $pdo, $stmt, string $sql, bool $executed): ObjectEntry
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
        $state->fetchMode = VmPDO::state($pdo)->fetchMode;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
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
