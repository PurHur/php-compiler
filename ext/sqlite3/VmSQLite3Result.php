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
 * SQLite3Result VM class (php-src ext/sqlite3/sqlite3.c; #19821).
 */
final class VmSQLite3Result
{
    public const CLASS_LC = 'sqlite3result';

    /** @var array<int, Sqlite3ResultState> */
    private static array $store = [];

    private static ?ClassEntry $classEntry = null;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['fetcharray'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SQLite3Result');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'fetcharray' => new SQLite3ResultFetchArray(),
            'numcolumns' => new SQLite3ResultNumColumns(),
            'columnname' => new SQLite3ResultColumnName(),
            'columntype' => new SQLite3ResultColumnType(),
            'reset' => new SQLite3ResultReset(),
            'finalize' => new SQLite3ResultFinalize(),
        ];
        // fetchAll — PHP 8.5+ only (absent from PHP-8.4 stubs; peer of #27594 / #20600).
        if (Sqlite3ExtensionPolicy::advertisesPhp85Apis()) {
            $methods['fetchall'] = new SQLite3ResultFetchAll();
        }
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['fetcharray'] = 'fetchArray';
        if (isset($entry->methods['fetchall'])) {
            $entry->methodNames['fetchall'] = 'fetchAll';
        }
        $entry->methodNames['numcolumns'] = 'numColumns';
        $entry->methodNames['columnname'] = 'columnName';
        $entry->methodNames['columntype'] = 'columnType';

        self::$classEntry = $entry;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param \FFI\CData $stmt sqlite3_stmt*
     */
    public static function create(ObjectEntry $db, $stmt, bool $ownsStmt): ObjectEntry
    {
        if (null === self::$classEntry) {
            throw new \LogicException('SQLite3Result class not registered');
        }
        $entry = new ObjectEntry(self::$classEntry);
        $state = new Sqlite3ResultState();
        $state->dbId = $db->id;
        $state->stmt = $stmt;
        $state->ownsStmt = $ownsStmt;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    public static function state(ObjectEntry $entry): Sqlite3ResultState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('SQLite3Result object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on SQLite3Result', $label));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on SQLite3Result, %s given', $label, $object->class->name));
        }

        return $object;
    }
}

/** @internal */
final class Sqlite3ResultState
{
    public int $dbId = 0;

    /** @var \FFI\CData|null sqlite3_stmt* */
    public $stmt = null;

    public bool $ownsStmt = true;

    public bool $finalized = false;
}

abstract class SQLite3ResultMethod extends Sqlite3ClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmSQLite3Result::requireReceiver($frame->calledArgs[0], $label);
    }
}

final class SQLite3ResultFetchArray extends SQLite3ResultMethod
{
    public function __construct()
    {
        parent::__construct('fetchArray');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Result::fetchArray()');
        $st = VmSQLite3Result::state($receiver);
        if ($st->finalized || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $mode = Sqlite3Constants::BOTH;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'SQLite3Result::fetchArray', 0, 'mode', Sqlite3Constants::BOTH);
        }
        $rc = VmSqlite3Native::step($st->stmt);
        if (VmSqlite3Native::STEP_ROW !== $rc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
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
        $row = match (true) {
            ($mode & Sqlite3Constants::ASSOC) !== 0 && ($mode & Sqlite3Constants::NUM) !== 0 => $num + $assoc,
            ($mode & Sqlite3Constants::ASSOC) !== 0 => $assoc,
            default => $num,
        };
        if (null !== $frame->returnVar) {
            VmSQLite3::assignReturnValue($frame->returnVar, $row);
        }
    }
}

/**
 * php-src zim_SQLite3Result_fetchAll — fetch remaining rows into a list (#20600).
 */
final class SQLite3ResultFetchAll extends SQLite3ResultMethod
{
    public function __construct()
    {
        parent::__construct('fetchAll');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Result::fetchAll()');
        $st = VmSQLite3Result::state($receiver);
        if ($st->finalized || null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $mode = Sqlite3Constants::BOTH;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'SQLite3Result::fetchAll', 0, 'mode', Sqlite3Constants::BOTH);
        }
        $rows = [];
        while (true) {
            $rc = VmSqlite3Native::step($st->stmt);
            if (VmSqlite3Native::STEP_DONE === $rc) {
                break;
            }
            if (VmSqlite3Native::STEP_ROW !== $rc) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
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
            $rows[] = match (true) {
                ($mode & Sqlite3Constants::ASSOC) !== 0 && ($mode & Sqlite3Constants::NUM) !== 0 => $num + $assoc,
                ($mode & Sqlite3Constants::ASSOC) !== 0 => $assoc,
                default => $num,
            };
        }
        if (null !== $frame->returnVar) {
            VmSQLite3::assignReturnValue($frame->returnVar, $rows);
        }
    }
}

final class SQLite3ResultNumColumns extends SQLite3ResultMethod
{
    public function __construct()
    {
        parent::__construct('numColumns');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Result::numColumns()');
        $st = VmSQLite3Result::state($receiver);
        $count = (null !== $st->stmt && !$st->finalized) ? VmSqlite3Native::columnCount($st->stmt) : 0;
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}

final class SQLite3ResultColumnName extends SQLite3ResultMethod
{
    public function __construct()
    {
        parent::__construct('columnName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Result::columnName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3Result::columnName() expects exactly 1 argument, 0 given');
        }
        $st = VmSQLite3Result::state($receiver);
        $column = $this->intArg($frame->calledArgs[1], 'SQLite3Result::columnName', 0, 'column');
        if (null === $st->stmt || $st->finalized) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmSqlite3Native::columnName($st->stmt, $column));
        }
    }
}

final class SQLite3ResultColumnType extends SQLite3ResultMethod
{
    public function __construct()
    {
        parent::__construct('columnType');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Result::columnType()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3Result::columnType() expects exactly 1 argument, 0 given');
        }
        $st = VmSQLite3Result::state($receiver);
        $column = $this->intArg($frame->calledArgs[1], 'SQLite3Result::columnType', 0, 'column');
        if (null === $st->stmt || $st->finalized) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        // php-src: RETURN_FALSE when !sqlite3_data_count (no current row after reset).
        if (VmSqlite3Native::dataCount($st->stmt) <= 0) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmSqlite3Native::columnTypeAt($st->stmt, $column));
        }
    }
}

final class SQLite3ResultReset extends SQLite3ResultMethod
{
    public function __construct()
    {
        parent::__construct('reset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Result::reset()');
        $st = VmSQLite3Result::state($receiver);
        if (null !== $st->stmt && !$st->finalized) {
            VmSqlite3Native::reset($st->stmt);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class SQLite3ResultFinalize extends SQLite3ResultMethod
{
    public function __construct()
    {
        parent::__construct('finalize');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3Result::finalize()');
        $st = VmSQLite3Result::state($receiver);
        if (null !== $st->stmt && !$st->finalized && $st->ownsStmt) {
            VmSqlite3Native::finalize($st->stmt);
            $st->stmt = null;
        }
        $st->finalized = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
