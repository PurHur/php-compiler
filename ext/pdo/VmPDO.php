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
use PHPCompiler\ext\sqlite3\Sqlite3Constants;
use PHPCompiler\ext\sqlite3\VmSqlite3Native;

/**
 * PDO VM class (php-src ext/pdo/pdo_dbh.c; #3367).
 *
 * Phase-1 subset: sqlite DSN (`sqlite::memory:`, `sqlite:/path`) + exec/prepare/query.
 */
final class VmPDO
{
    public const CLASS_LC = 'pdo';

    /** @var array<int, PdoState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['exec'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('PDO');
        $entry->isInternal = true;
        foreach (PdoConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = $name;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new PDOConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'exec' => new PDOExec(),
            'prepare' => new PDOPrepare(),
            'query' => new PDOQuery(),
            'setattribute' => new PDOSetAttribute(),
            'getattribute' => new PDOGetAttribute(),
            'getavailabledrivers' => new PDOGetAvailableDrivers(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['setattribute'] = 'setAttribute';
        $entry->methodNames['getattribute'] = 'getAttribute';
        $entry->methodNames['getavailabledrivers'] = 'getAvailableDrivers';
        $entry->methodVisibility['getavailabledrivers'] = CfgFunc::FLAG_STATIC | $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function stateById(int $id): PdoState
    {
        if (!isset(self::$store[$id])) {
            throw new \LogicException('PDO object has not been correctly initialized by its constructor');
        }

        return self::$store[$id];
    }

    public static function initObject(ObjectEntry $entry, string $filename): void
    {
        if (!VmSqlite3Native::available()) {
            throw new \PDOException('could not find driver');
        }
        $flags = Sqlite3Constants::OPEN_READWRITE | Sqlite3Constants::OPEN_CREATE;
        try {
            $db = VmSqlite3Native::open($filename, $flags);
        } catch (\SQLite3Exception $e) {
            throw new \PDOException($e->getMessage(), (int) $e->getCode(), $e);
        }
        $state = new PdoState();
        $state->db = $db;
        $state->filename = $filename;
        $state->errMode = PdoConstants::ERRMODE_EXCEPTION;
        $state->fetchMode = PdoConstants::FETCH_BOTH;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
    }

    public static function state(ObjectEntry $entry): PdoState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('PDO object has not been correctly initialized by its constructor');
        }

        return self::$store[$entry->id];
    }

    public static function requireDb(ObjectEntry $entry): \FFI\CData
    {
        $state = self::state($entry);
        if (null === $state->db) {
            throw new \PDOException('PDO object has not been correctly initialized by its constructor');
        }

        return $state->db;
    }

    public static function parseSqliteDsn(string $dsn): string
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            throw new \PDOException('could not find driver');
        }
        $path = substr($dsn, \strlen('sqlite:'));
        if ('' === $path || 'memory:' === $path || ':memory:' === $path) {
            return ':memory:';
        }
        // php-src accepts sqlite::memory: (extra colon) as memory DB.
        if (':memory:' === $path || 'memory:' === ltrim($path, ':')) {
            return ':memory:';
        }

        return $path;
    }

    public static function raise(PdoState $state, string $message, string $sqlState = 'HY000'): void
    {
        if (PdoConstants::ERRMODE_EXCEPTION === $state->errMode) {
            $ex = new \PDOException($message);
            $ex->errorInfo = [$sqlState, null, $message];
            throw $ex;
        }
        if (PdoConstants::ERRMODE_WARNING === $state->errMode) {
            trigger_error('SQLSTATE['.$sqlState.']: '.$message, E_USER_WARNING);
        }
    }

    public static function assignScalar(Variable $returnVar, mixed $value): void
    {
        if (null === $value) {
            $returnVar->null();
        } elseif (\is_int($value)) {
            $returnVar->int($value);
        } elseif (\is_float($value)) {
            $returnVar->float($value);
        } elseif (\is_bool($value)) {
            $returnVar->bool($value);
        } else {
            $returnVar->string((string) $value);
        }
    }

    public static function assignRow(Variable $returnVar, array $row): void
    {
        $ht = new HashTable();
        foreach ($row as $key => $item) {
            $slot = new Variable();
            self::assignScalar($slot, $item);
            $ht->add(\is_int($key) ? (string) $key : $key, $slot);
        }
        $returnVar->array($ht);
    }

    public static function phpValueFromVariable(Variable $var): mixed
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $resolved->toBool(),
            Variable::TYPE_INTEGER => $resolved->toInt(),
            Variable::TYPE_FLOAT => $resolved->toFloat(),
            Variable::TYPE_STRING => $resolved->toString(),
            default => throw new \TypeError('PDO parameter must be a scalar or null'),
        };
    }
}

/** @internal */
final class PdoState
{
    /** @var \FFI\CData|null sqlite3* */
    public $db = null;

    public string $filename = '';

    public int $errMode = PdoConstants::ERRMODE_EXCEPTION;

    public int $fetchMode = PdoConstants::FETCH_BOTH;
}

final class PDOConstruct extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDO::__construct() expects at least 1 argument, 0 given');
        }
        $receiver = $this->receiver($frame, 'PDO::__construct()');
        if (VmPDO::CLASS_LC !== strtolower($receiver->class->name)) {
            throw new \TypeError('PDO::__construct() must be called on PDO');
        }
        if ($receiver->constructed) {
            throw new \LogicException('PDO object already initialized');
        }
        $dsn = $this->stringArg($frame->calledArgs[1], 'PDO::__construct', 0, 'dsn');
        // username/password/options ignored for sqlite subset.
        $filename = VmPDO::parseSqliteDsn($dsn);
        VmPDO::initObject($receiver, $filename);
    }
}

final class PDOExec extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('exec');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::exec()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDO::exec() expects exactly 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'PDO::exec', 0, 'statement');
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        try {
            VmSqlite3Native::exec($db, $sql);
            $changes = VmSqlite3Native::changes($db);
        } catch (\SQLite3Exception $e) {
            VmPDO::raise($state, $e->getMessage());
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($changes);
        }
    }
}

final class PDOPrepare extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('prepare');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::prepare()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDO::prepare() expects at least 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'PDO::prepare', 0, 'query');
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        try {
            $stmt = VmSqlite3Native::prepare($db, $sql);
        } catch (\SQLite3Exception $e) {
            VmPDO::raise($state, $e->getMessage());
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $statement = VmPDOStatement::create($receiver, $stmt, $sql, false);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($statement);
        }
    }
}

final class PDOQuery extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDO::query() expects at least 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'PDO::query', 0, 'query');
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        try {
            $stmt = VmSqlite3Native::prepare($db, $sql);
            // Execute immediately (php-src PDO::query).
            $rc = VmSqlite3Native::step($stmt);
            if (VmSqlite3Native::STEP_ROW !== $rc && VmSqlite3Native::STEP_DONE !== $rc) {
                $msg = VmSqlite3Native::errmsg($db);
                VmSqlite3Native::finalize($stmt);
                VmPDO::raise($state, $msg);
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            // Rewind so Iterator/fetch can re-step from the start.
            VmSqlite3Native::reset($stmt);
        } catch (\SQLite3Exception $e) {
            VmPDO::raise($state, $e->getMessage());
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $statement = VmPDOStatement::create($receiver, $stmt, $sql, true);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($statement);
        }
    }
}

final class PDOSetAttribute extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttribute');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::setAttribute()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'PDO::setAttribute() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $attr = $this->intArg($frame->calledArgs[1], 'PDO::setAttribute', 0, 'attribute');
        $value = $this->intArg($frame->calledArgs[2], 'PDO::setAttribute', 1, 'value');
        $state = VmPDO::state($receiver);
        if (PdoConstants::ATTR_ERRMODE === $attr) {
            $state->errMode = $value;
        } elseif (PdoConstants::ATTR_DEFAULT_FETCH_MODE === $attr) {
            $state->fetchMode = $value;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class PDOGetAttribute extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttribute');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::getAttribute()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDO::getAttribute() expects exactly 1 argument, 0 given');
        }
        $attr = $this->intArg($frame->calledArgs[1], 'PDO::getAttribute', 0, 'attribute');
        $state = VmPDO::state($receiver);
        $value = match ($attr) {
            PdoConstants::ATTR_ERRMODE => $state->errMode,
            PdoConstants::ATTR_DEFAULT_FETCH_MODE => $state->fetchMode,
            default => 0,
        };
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($value);
        }
    }
}

final class PDOGetAvailableDrivers extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('getAvailableDrivers');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            $slot = new Variable();
            $slot->string('sqlite');
            $ht->add('0', $slot);
        }
        $frame->returnVar->array($ht);
    }
}
