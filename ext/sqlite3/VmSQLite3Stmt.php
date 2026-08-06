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
 * SQLite3Stmt VM class (php-src ext/sqlite3/sqlite3.c; #19821, #19854).
 */
final class VmSQLite3Stmt
{
    public const CLASS_LC = 'sqlite3stmt';

    /** @var array<int, Sqlite3StmtState> */
    private static array $store = [];

    private static ?ClassEntry $classEntry = null;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['bindparam'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SQLite3Stmt');
        $entry->isInternal = true;
        // Declared casing is the storage key (ClassConstName / #25929); STMT map
        // keys are lowercase legacy labels — use CLASS_CONSTANT_NAMES (#28098).
        // EXPLAIN_MODE_* are PHP 8.5+ (absent from PHP-8.4 stubs; #27594).
        if (Sqlite3ExtensionPolicy::advertisesPhp85Apis()) {
            foreach (Sqlite3Constants::STMT_CLASS_CONSTANTS as $name => $value) {
                $const = new Variable(Variable::TYPE_INTEGER);
                $const->int($value);
                $canonical = Sqlite3Constants::STMT_CLASS_CONSTANT_NAMES[$name];
                $entry->constants[$canonical] = $const;
                $entry->constNames[$canonical] = $canonical;
            }
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'bindparam' => new SQLite3StmtBindParam(),
            'bindvalue' => new SQLite3StmtBindValue(),
            'clear' => new SQLite3StmtClear(),
            'close' => new SQLite3StmtClose(),
            'execute' => new SQLite3StmtExecute(),
            'getsql' => new SQLite3StmtGetSQL(),
            'paramcount' => new SQLite3StmtParamCount(),
            'readonly' => new SQLite3StmtReadOnly(),
            'reset' => new SQLite3StmtReset(),
        ];
        // busy/explain/setExplain — PHP 8.5+ only (#27594; migration85.new-functions).
        if (Sqlite3ExtensionPolicy::advertisesPhp85Apis()) {
            $methods['busy'] = new SQLite3StmtBusy();
            $methods['explain'] = new SQLite3StmtExplain();
            $methods['setexplain'] = new SQLite3StmtSetExplain();
        }
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['bindparam'] = 'bindParam';
        $entry->methodNames['bindvalue'] = 'bindValue';
        $entry->methodNames['getsql'] = 'getSQL';
        $entry->methodNames['paramcount'] = 'paramCount';
        $entry->methodNames['readonly'] = 'readOnly';
        if (isset($entry->methods['setexplain'])) {
            $entry->methodNames['setexplain'] = 'setExplain';
        }

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

    /**
     * Resolve 1-based bind index from int or named placeholder (php-src register_bound_parameter_to_sqlite).
     *
     * @param \FFI\CData $stmt
     */
    public static function resolveParamIndex($stmt, Variable $paramVar, string $label): ?int
    {
        $resolved = $paramVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $n = $resolved->toInt();

            return $n >= 1 ? $n : null;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $name = $resolved->toString();
            if ('' === $name) {
                return null;
            }
            if (':' !== $name[0] && '@' !== $name[0]) {
                $name = ':'.$name;
            }
            $idx = VmSqlite3Native::bindParameterIndex($stmt, $name);

            return $idx >= 1 ? $idx : null;
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            $n = (int) $resolved->toFloat();

            return $n >= 1 ? $n : null;
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            $n = $resolved->toBool() ? 1 : 0;

            return $n >= 1 ? $n : null;
        }

        throw new \TypeError(
            \sprintf('%s(): Argument #1 ($param) must be of type string|int', $label)
        );
    }

    public static function scalarFromVariable(Variable $var, string $label): mixed
    {
        $valueVar = $var->resolveIndirect();

        return match ($valueVar->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $valueVar->toBool(),
            Variable::TYPE_INTEGER => $valueVar->toInt(),
            Variable::TYPE_FLOAT => $valueVar->toFloat(),
            Variable::TYPE_STRING => $valueVar->toString(),
            default => throw new \TypeError(
                \sprintf('%s(): Argument #2 ($value) must be of type scalar|null', $label)
            ),
        };
    }

    /**
     * Apply stored binds to the native statement (php_sqlite3_bind_params).
     *
     * @param \FFI\CData $stmt
     */
    public static function applyBindings(Sqlite3StmtState $st, $stmt): bool
    {
        foreach ($st->bound as $index => $entry) {
            try {
                if ('param' === $entry['kind']) {
                    $value = self::scalarFromVariable($entry['var'], 'SQLite3Stmt::execute');
                } else {
                    $value = $entry['value'];
                }
                VmSqlite3Native::bindValue($stmt, (int) $index, $value);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
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

    /**
     * 1-based param index => bind entry (php-src bound_params).
     *
     * @var array<int, array{kind: 'value', value: mixed}|array{kind: 'param', var: Variable}>
     */
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

final class SQLite3StmtBindParam extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('bindParam');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::bindParam()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SQLite3Stmt::bindParam() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $st = VmSQLite3Stmt::state($receiver);
        if ($st->closed || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $param = VmSQLite3Stmt::resolveParamIndex($st->stmt, $frame->calledArgs[1], 'SQLite3Stmt::bindParam');
        if (null === $param) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        // Keep the Variable slot (often TYPE_INDIRECT) so execute() sees live updates (#19854).
        $st->bound[$param] = ['kind' => 'param', 'var' => $frame->calledArgs[2]];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
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
        $param = VmSQLite3Stmt::resolveParamIndex($st->stmt, $frame->calledArgs[1], 'SQLite3Stmt::bindValue');
        if (null === $param) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $value = VmSQLite3Stmt::scalarFromVariable($frame->calledArgs[2], 'SQLite3Stmt::bindValue');
        $st->bound[$param] = ['kind' => 'value', 'value' => $value];
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
            // Always reset before execution (php-src #77051).
            VmSqlite3Native::reset($st->stmt);
            VmSqlite3Native::clearBindings($st->stmt);
            if (!VmSQLite3Stmt::applyBindings($st, $st->stmt)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
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

final class SQLite3StmtGetSQL extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('getSQL');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::getSQL()');
        $st = VmSQLite3Stmt::state($receiver);
        if ($st->closed || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $expand = false;
        if (\count($frame->calledArgs) >= 2) {
            $expand = $this->boolArg($frame->calledArgs[1], 'SQLite3Stmt::getSQL', 0, 'expand', false);
        }
        if ($expand) {
            VmSqlite3Native::clearBindings($st->stmt);
            if (!VmSQLite3Stmt::applyBindings($st, $st->stmt)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $sql = VmSqlite3Native::expandedSql($st->stmt);
            if (null === $sql) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->string($sql);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmSqlite3Native::sql($st->stmt));
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

final class SQLite3StmtReadOnly extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('readOnly');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::readOnly()');
        $st = VmSQLite3Stmt::state($receiver);
        $ro = (null !== $st->stmt && !$st->closed) ? VmSqlite3Native::stmtReadonly($st->stmt) : false;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ro);
        }
    }
}

/** php-src zim_SQLite3Stmt_busy — sqlite3_stmt_busy (#20600). */
final class SQLite3StmtBusy extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('busy');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::busy()');
        $st = VmSQLite3Stmt::state($receiver);
        $busy = (null !== $st->stmt && !$st->closed) ? VmSqlite3Native::stmtBusy($st->stmt) : false;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($busy);
        }
    }
}

/**
 * php-src zim_SQLite3Stmt_explain — returns current explain mode (#20600).
 * Requires host SQLite ≥ 3.43; otherwise throws Error like php-src Apple fallback.
 */
final class SQLite3StmtExplain extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('explain');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::explain()');
        $st = VmSQLite3Stmt::state($receiver);
        if ($st->closed || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->int(0);
            }

            return;
        }
        $mode = VmSqlite3Native::stmtIsExplain($st->stmt);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($mode);
        }
    }
}

/**
 * php-src zim_SQLite3Stmt_setExplain — set EXPLAIN / EXPLAIN QUERY PLAN mode (#20600).
 */
final class SQLite3StmtSetExplain extends SQLite3StmtMethod
{
    public function __construct()
    {
        parent::__construct('setExplain');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Stmt::setExplain()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3Stmt::setExplain() expects exactly 1 argument, 0 given');
        }
        $mode = $this->intArg($frame->calledArgs[1], 'SQLite3Stmt::setExplain', 0, 'mode');
        if ($mode < 0 || $mode > 2) {
            throw new \ValueError(
                'SQLite3Stmt::setExplain(): Argument #1 ($mode) must be one of the SQLite3Stmt::EXPLAIN_MODE_* constants'
            );
        }
        $st = VmSQLite3Stmt::state($receiver);
        if ($st->closed || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ok = VmSqlite3Native::stmtExplain($st->stmt, $mode);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
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
