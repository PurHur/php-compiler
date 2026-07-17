<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * SQLite3Stmt VM class (php-src ext/sqlite3/sqlite3.c; #19821).
 */
final class VmSQLite3Stmt
{
    public const CLASS_LC = 'sqlite3stmt';

    /** @var array<int, Sqlite3StmtState> */
    private static array $store = [];

    private static ?ClassEntry $classEntry = null;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['execute'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SQLite3Stmt');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        foreach ([
            'bindvalue' => new SQLite3StmtBindValue(),
            'clear' => new SQLite3StmtClear(),
            'close' => new SQLite3StmtClose(),
            'execute' => new SQLite3StmtExecute(),
            'paramcount' => new SQLite3StmtParamCount(),
            'reset' => new SQLite3StmtReset(),
        ] as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['bindvalue'] = 'bindValue';
        $entry->methodNames['paramcount'] = 'paramCount';

        self::$classEntry = $entry;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param \FFI\CData $stmt sqlite3_stmt*
     */
    public static function create(ObjectEntry $db, $stmt, string $sql): ObjectEntry
    {
        if (null === self::$classEntry) {
            throw new \LogicException('SQLite3Stmt class not registered');
        }
        $entry = new ObjectEntry(self::$classEntry);
        $state = new Sqlite3StmtState();
        $state->dbId = $db->id;
        $state->stmt = $stmt;
        $state->sql = $sql;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    public static function state(ObjectEntry $entry): Sqlite3StmtState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('SQLite3Stmt object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on SQLite3Stmt', $label));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on SQLite3Stmt, %s given', $label, $object->class->name));
        }

        return $object;
    }
}

/** @internal */
final class Sqlite3StmtState
{
    public int $dbId = 0;

    /** @var \FFI\CData|null sqlite3_stmt* */
    public $stmt = null;

    public string $sql = '';

    public bool $closed = false;

    /** @var array<int, mixed> 1-based param index => value */
    public array $bound = [];
}

abstract class SQLite3StmtMethod extends Sqlite3ClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmSQLite3Stmt::requireReceiver($frame->calledArgs[0], $label);
    }
}

final class SQLite3StmtBindValue extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('bindValue');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::bindValue()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SQLite3Stmt::bindValue() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $st = VmSQLite3Stmt::state($receiver);
        if ($st->closed || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $param = $this->intArg($frame->calledArgs[1], 'SQLite3Stmt::bindValue', 0, 'param');
        $valueVar = $frame->calledArgs[2]->resolveIndirect();
        $value = match ($valueVar->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $valueVar->toBool(),
            Variable::TYPE_INTEGER => $valueVar->toInt(),
            Variable::TYPE_FLOAT => $valueVar->toFloat(),
            Variable::TYPE_STRING => $valueVar->toString(),
            default => throw new \TypeError('SQLite3Stmt::bindValue(): Argument #2 ($value) must be of type scalar|null'),
        };
        $st->bound[$param] = $value;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class SQLite3StmtClear extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('clear');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::clear()');
        $st = VmSQLite3Stmt::state($receiver);
        $st->bound = [];
        if (null !== $st->stmt && !$st->closed) {
            VmSqlite3Native::clearBindings($st->stmt);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class SQLite3StmtClose extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::close()');
        $st = VmSQLite3Stmt::state($receiver);
        if (null !== $st->stmt && !$st->closed) {
            VmSqlite3Native::finalize($st->stmt);
            $st->stmt = null;
        }
        $st->closed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class SQLite3StmtExecute extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('execute');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::execute()');
        $st = VmSQLite3Stmt::state($receiver);
        if ($st->closed || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        try {
            VmSqlite3Native::reset($st->stmt);
            VmSqlite3Native::clearBindings($st->stmt);
            foreach ($st->bound as $index => $value) {
                VmSqlite3Native::bindValue($st->stmt, (int) $index, $value);
            }
            $rc = VmSqlite3Native::step($st->stmt);
            if (VmSqlite3Native::STEP_ROW !== $rc && VmSqlite3Native::STEP_DONE !== $rc) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            VmSqlite3Native::reset($st->stmt);
            $dbObject = VmSQLite3::objectById($st->dbId);
            $result = VmSQLite3Result::create($dbObject, $st->stmt, false);
            if (null !== $frame->returnVar) {
                $frame->returnVar->object($result);
            }
        } catch (\SQLite3Exception $e) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }
        }
    }
}

final class SQLite3StmtParamCount extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('paramCount');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::paramCount()');
        $st = VmSQLite3Stmt::state($receiver);
        $count = (null !== $st->stmt && !$st->closed) ? VmSqlite3Native::bindParameterCount($st->stmt) : 0;
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}

final class SQLite3StmtReset extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('reset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::reset()');
        $st = VmSQLite3Stmt::state($receiver);
        if (null !== $st->stmt && !$st->closed) {
            VmSqlite3Native::reset($st->stmt);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
