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
use PHPCompiler\ext\sqlite3\VmSqlite3Udf;
use PHPCompiler\ext\spl\SplIteratorSupport;
use PHPCompiler\ext\standard\VmCallable;

/**
 * PDO VM class (php-src ext/pdo/pdo_dbh.c; #3367).
 *
 * Phase-1 subset: sqlite DSN (`sqlite::memory:`, `sqlite:/path`) + exec/prepare/query.
 */
final class VmPDO
{
    public const CLASS_LC = 'pdo';

    public const SQLITE_CLASS_LC = 'pdo\\sqlite';

    public const SQLITE_CLASS_NAME = 'Pdo\\Sqlite';

    public const MYSQL_CLASS_LC = 'pdo\\mysql';

    public const MYSQL_CLASS_NAME = 'Pdo\\Mysql';

    public const PGSQL_CLASS_LC = 'pdo\\pgsql';

    public const PGSQL_CLASS_NAME = 'Pdo\\Pgsql';

    /** @var array<int, PdoState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['exec'])) {
            self::registerDriverSubclasses($ctx);

            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('PDO');
        $entry->isInternal = true;
        foreach (PdoConstants::CLASS_CONSTANTS as $name => $value) {
            if (\is_string($value)) {
                $const = new Variable(Variable::TYPE_STRING);
                $const->string($value);
            } else {
                $const = new Variable(Variable::TYPE_INTEGER);
                $const->int($value);
            }
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = PdoConstants::CLASS_CONSTANT_NAMES[$name];
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
            'connect' => new PDOConnect(),
            'lastinsertid' => new PDOLastInsertId(),
            'quote' => new PDOQuote(),
            'begintransaction' => new PDOBeginTransaction(),
            'commit' => new PDOCommit(),
            'rollback' => new PDORollBack(),
            'intransaction' => new PDOInTransaction(),
            'errorcode' => new PDOErrorCode(),
            'errorinfo' => new PDOErrorInfo(),
            'sqlitecreatefunction' => new PDOSqliteCreateFunction(),
            'sqlitecreateaggregate' => new PDOSqliteCreateAggregate(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['setattribute'] = 'setAttribute';
        $entry->methodNames['getattribute'] = 'getAttribute';
        $entry->methodNames['getavailabledrivers'] = 'getAvailableDrivers';
        $entry->methodNames['lastinsertid'] = 'lastInsertId';
        $entry->methodNames['begintransaction'] = 'beginTransaction';
        $entry->methodNames['rollback'] = 'rollBack';
        $entry->methodNames['intransaction'] = 'inTransaction';
        $entry->methodNames['errorcode'] = 'errorCode';
        $entry->methodNames['errorinfo'] = 'errorInfo';
        $entry->methodNames['sqlitecreatefunction'] = 'sqliteCreateFunction';
        $entry->methodNames['sqlitecreateaggregate'] = 'sqliteCreateAggregate';
        $entry->methodVisibility['getavailabledrivers'] = CfgFunc::FLAG_STATIC | $pub;
        $entry->methodVisibility['connect'] = CfgFunc::FLAG_STATIC | $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
        self::registerDriverSubclasses($ctx);
    }

    /** PHP 8.4 driver-specific subclasses (#20529, #20548). */
    public static function registerDriverSubclasses(Context $ctx): void
    {
        self::registerSqliteSubclass($ctx);
        self::registerMysqlSubclass($ctx);
        self::registerPgsqlSubclass($ctx);
    }

    /**
     * PHP 8.4 driver-specific subclass (php-src ext/pdo_sqlite/pdo_sqlite.stub.php; #20529).
     */
    public static function registerSqliteSubclass(Context $ctx): void
    {
        if (!PdoExtensionPolicy::advertisesSqliteDriver()) {
            return;
        }
        if (isset($ctx->classes[self::SQLITE_CLASS_LC])) {
            return;
        }
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $sqlite = new ClassEntry(self::SQLITE_CLASS_NAME);
        $sqlite->isInternal = true;
        $sqlite->parentLc = self::CLASS_LC;
        $ctx->classes[self::SQLITE_CLASS_LC] = $sqlite;
    }

    /**
     * PHP 8.4 Pdo\Mysql (php-src ext/pdo_mysql/pdo_mysql.stub.php; #20548).
     *
     * Native mysql connection factory is not wired yet — class/constants/methods only.
     */
    public static function registerMysqlSubclass(Context $ctx): void
    {
        if (!PdoExtensionPolicy::advertisesMysqlSubclass()) {
            return;
        }
        if (isset($ctx->classes[self::MYSQL_CLASS_LC])) {
            return;
        }
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $mysql = new ClassEntry(self::MYSQL_CLASS_NAME);
        $mysql->isInternal = true;
        $mysql->parentLc = self::CLASS_LC;
        foreach (PdoMysqlConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $mysql->constants[$name] = $const;
            $mysql->constNames[$name] = PdoMysqlConstants::CLASS_CONSTANT_NAMES[$name];
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $mysql->methods['getwarningcount'] = new PDOMysqlGetWarningCount();
        $mysql->methodVisibility['getwarningcount'] = $pub;
        $mysql->methodNames['getwarningcount'] = 'getWarningCount';
        $ctx->classes[self::MYSQL_CLASS_LC] = $mysql;
    }

    /**
     * PHP 8.4 Pdo\Pgsql (php-src ext/pdo_pgsql/pdo_pgsql.stub.php; #20548).
     *
     * Native pgsql connection factory is not wired yet — class/constants/methods only.
     */
    public static function registerPgsqlSubclass(Context $ctx): void
    {
        if (!PdoExtensionPolicy::advertisesPgsqlSubclass()) {
            return;
        }
        if (isset($ctx->classes[self::PGSQL_CLASS_LC])) {
            return;
        }
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $pgsql = new ClassEntry(self::PGSQL_CLASS_NAME);
        $pgsql->isInternal = true;
        $pgsql->parentLc = self::CLASS_LC;
        foreach (PdoPgsqlConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $pgsql->constants[$name] = $const;
            $pgsql->constNames[$name] = PdoPgsqlConstants::CLASS_CONSTANT_NAMES[$name];
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'escapeidentifier' => [new PDOPgsqlEscapeIdentifier(), 'escapeIdentifier'],
            'copyfromarray' => [new PDOPgsqlCopyFromArray(), 'copyFromArray'],
            'copyfromfile' => [new PDOPgsqlCopyFromFile(), 'copyFromFile'],
            'copytoarray' => [new PDOPgsqlCopyToArray(), 'copyToArray'],
            'copytofile' => [new PDOPgsqlCopyToFile(), 'copyToFile'],
            'lobcreate' => [new PDOPgsqlLobCreate(), 'lobCreate'],
            'lobopen' => [new PDOPgsqlLobOpen(), 'lobOpen'],
            'lobunlink' => [new PDOPgsqlLobUnlink(), 'lobUnlink'],
            'getnotify' => [new PDOPgsqlGetNotify(), 'getNotify'],
            'getpid' => [new PDOPgsqlGetPid(), 'getPid'],
            'setnoticecallback' => [new PDOPgsqlSetNoticeCallback(), 'setNoticeCallback'],
        ];
        foreach ($methods as $lc => [$method, $display]) {
            $pgsql->methods[$lc] = $method;
            $pgsql->methodVisibility[$lc] = $pub;
            $pgsql->methodNames[$lc] = $display;
        }
        $ctx->classes[self::PGSQL_CLASS_LC] = $pgsql;
    }

    public static function isPdoFamily(ClassEntry $class): bool
    {
        $lc = \strtolower($class->name);
        if (self::CLASS_LC === $lc
            || self::SQLITE_CLASS_LC === $lc
            || self::MYSQL_CLASS_LC === $lc
            || self::PGSQL_CLASS_LC === $lc
        ) {
            return true;
        }

        return self::CLASS_LC === ($class->parentLc ?? '');
    }

    /**
     * Allocate and open a PDO (or driver subclass) handle from a DSN (#20529, #20548).
     *
     * mysql:/pgsql: throw "could not find driver" until native factories land — subclasses
     * still exist for class_exists / constants / method_exists.
     */
    public static function connect(Context $ctx, string $dsn): ObjectEntry
    {
        $driver = self::dsnDriverPrefix($dsn);
        if ('mysql' === $driver || 'pgsql' === $driver) {
            // Driver subclass may be registered without a live factory (profile ≥ 8.4).
            throw new \PDOException('could not find driver');
        }
        $filename = self::parseSqliteDsn($dsn);
        $class = $ctx->classes[self::SQLITE_CLASS_LC] ?? $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('PDO is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        self::initObject($entry, $filename);

        return $entry;
    }

    /** DSN scheme before the first ':' (php-src pdo_find_driver). */
    public static function dsnDriverPrefix(string $dsn): string
    {
        $colon = \strpos($dsn, ':');
        if (false === $colon || 0 === $colon) {
            return '';
        }

        return \strtolower(\substr($dsn, 0, $colon));
    }

    /**
     * Driver name list for PDO::getAvailableDrivers() / pdo_drivers() (php-src ext/pdo/pdo.c; #20239).
     */
    public static function availableDriversHashTable(): HashTable
    {
        $ht = new HashTable();
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            $slot = new Variable();
            $slot->string('sqlite');
            $ht->add('0', $slot);
        }

        return $ht;
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

    public static function clearError(PdoState $state): void
    {
        $state->errorCode = '00000';
        $state->errorDriverCode = null;
        $state->errorMessage = null;
    }

    public static function raise(PdoState $state, string $message, string $sqlState = 'HY000', ?int $driverCode = null): void
    {
        $state->errorCode = $sqlState;
        $state->errorDriverCode = $driverCode;
        $state->errorMessage = $message;
        if (PdoConstants::ERRMODE_EXCEPTION === $state->errMode) {
            $ex = new \PDOException($message);
            $ex->errorInfo = [$sqlState, $driverCode, $message];
            throw $ex;
        }
        if (PdoConstants::ERRMODE_WARNING === $state->errMode) {
            trigger_error('SQLSTATE['.$sqlState.']: '.$message, E_USER_WARNING);
        }
    }

    /** Run a sqlite exec and map SQLite3Exception through {@see raise()}. */
    public static function execSql(PdoState $state, \FFI\CData $db, string $sql): void
    {
        try {
            VmSqlite3Native::exec($db, $sql);
            self::clearError($state);
        } catch (\SQLite3Exception $e) {
            self::raise($state, $e->getMessage(), 'HY000', (int) $e->getCode());
        }
    }

    /** Expand PDO sqliteCreateFunction UDFs in SQL (#19863 / #19862). */
    public static function expandSql(ObjectEntry $entry, string $sql): string
    {
        $state = self::state($entry);
        if ([] === $state->functions) {
            return $sql;
        }

        return VmSqlite3Udf::expandSql($sql, $state->functions);
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

    /** Active txn flag (php-src pdo_dbh_t.in_txn). */
    public bool $inTransaction = false;

    public string $errorCode = '00000';

    public ?int $errorDriverCode = null;

    public ?string $errorMessage = null;

    /**
     * PDO::sqliteCreateFunction registrations (share expand with SQLite3; #19863).
     *
     * @var array<string, array{callback: Variable, closure: ?\PHPCompiler\VM\ClosureState, argc: int, ctx: \PHPCompiler\VM\Context}>
     */
    public array $functions = [];
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
        if (!VmPDO::isPdoFamily($receiver->class)) {
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

/** PDO::connect() — PHP 8.4 driver-specific factory (php-src ext/pdo/pdo_dbh.c; #20529). */
final class PDOConnect extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Static call: user args only (no $this). Guard if an instance somehow prepends self.
        $argOffset = 0;
        if ($argc >= 1) {
            $maybeThis = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $maybeThis->type
                && VmPDO::isPdoFamily($maybeThis->toObject()->class)
            ) {
                $argOffset = 1;
            }
        }
        $userArgc = $argc - $argOffset;
        if ($userArgc < 1) {
            throw new \ArgumentCountError(
                'PDO::connect() expects at least 1 argument, '.$userArgc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDO::connect() requires a VM context');
        }
        $dsn = $this->stringArg($frame->calledArgs[$argOffset], 'PDO::connect', 0, 'dsn');
        // username/password/options ignored for sqlite subset (same as __construct).
        $frame->returnVar->object(VmPDO::connect($ctx, $dsn));
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
        $sql = VmPDO::expandSql(
            $receiver,
            $this->stringArg($frame->calledArgs[1], 'PDO::exec', 0, 'statement')
        );
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        try {
            VmSqlite3Native::exec($db, $sql);
            VmPDO::clearError($state);
            $changes = VmSqlite3Native::changes($db);
        } catch (\SQLite3Exception $e) {
            VmPDO::raise($state, $e->getMessage(), 'HY000', (int) $e->getCode());
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
        $sql = VmPDO::expandSql(
            $receiver,
            $this->stringArg($frame->calledArgs[1], 'PDO::prepare', 0, 'query')
        );
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        try {
            $stmt = VmSqlite3Native::prepare($db, $sql);
            VmPDO::clearError($state);
        } catch (\SQLite3Exception $e) {
            VmPDO::raise($state, $e->getMessage(), 'HY000', (int) $e->getCode());
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
        $sql = VmPDO::expandSql(
            $receiver,
            $this->stringArg($frame->calledArgs[1], 'PDO::query', 0, 'query')
        );
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
            $rowCount = VmPDOStatement::rowCountAfterStep($stmt, $db, $rc);
            // Rewind so Iterator/fetch can re-step from the start.
            VmSqlite3Native::reset($stmt);
            VmPDO::clearError($state);
        } catch (\SQLite3Exception $e) {
            VmPDO::raise($state, $e->getMessage(), 'HY000', (int) $e->getCode());
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $statement = VmPDOStatement::create($receiver, $stmt, $sql, true, $rowCount);
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
        $frame->returnVar->array(VmPDO::availableDriversHashTable());
    }
}

/** PDO::lastInsertId(?string $name = null): string|false — php-src zim_PDO_lastInsertId (#19861). */
final class PDOLastInsertId extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('lastInsertId');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::lastInsertId()');
        // Optional $name ignored for sqlite (php-src pdo_sqlite last_insert_id).
        $db = VmPDO::requireDb($receiver);
        $state = VmPDO::state($receiver);
        VmPDO::clearError($state);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string((string) VmSqlite3Native::lastInsertRowId($db));
        }
    }
}

/** PDO::quote(string $string, int $type = PARAM_STR): string|false — sqlite %Q (#19861). */
final class PDOQuote extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('quote');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::quote()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDO::quote() expects at least 1 argument, 0 given');
        }
        $value = $this->stringArg($frame->calledArgs[1], 'PDO::quote', 0, 'string');
        // $type (arg 2) ignored for sqlite string quoting.
        VmPDO::requireDb($receiver);
        $state = VmPDO::state($receiver);
        VmPDO::clearError($state);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmSqlite3Native::quoteSqlLiteral($value));
        }
    }
}

/** PDO::beginTransaction(): bool — php-src zim_PDO_beginTransaction (#19861). */
final class PDOBeginTransaction extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('beginTransaction');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::beginTransaction()');
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        if ($state->inTransaction) {
            VmPDO::raise($state, 'There is already an active transaction');
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        VmPDO::execSql($state, $db, 'BEGIN');
        $state->inTransaction = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/** PDO::commit(): bool — php-src zim_PDO_commit (#19861). */
final class PDOCommit extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('commit');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::commit()');
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        if (!$state->inTransaction) {
            VmPDO::raise($state, 'There is no active transaction');
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        VmPDO::execSql($state, $db, 'COMMIT');
        $state->inTransaction = false;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/** PDO::rollBack(): bool — php-src zim_PDO_rollBack (#19861). */
final class PDORollBack extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('rollBack');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::rollBack()');
        $state = VmPDO::state($receiver);
        $db = VmPDO::requireDb($receiver);
        if (!$state->inTransaction) {
            VmPDO::raise($state, 'There is no active transaction');
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        VmPDO::execSql($state, $db, 'ROLLBACK');
        $state->inTransaction = false;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/** PDO::inTransaction(): bool — php-src zim_PDO_inTransaction (#19861). */
final class PDOInTransaction extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('inTransaction');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::inTransaction()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPDO::state($receiver)->inTransaction);
        }
    }
}

/** PDO::errorCode(): ?string — php-src zim_PDO_errorCode (#19861). */
final class PDOErrorCode extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('errorCode');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::errorCode()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmPDO::state($receiver)->errorCode);
        }
    }
}

/** PDO::errorInfo(): array — php-src zim_PDO_errorInfo (#19861). */
final class PDOErrorInfo extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('errorInfo');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::errorInfo()');
        if (null === $frame->returnVar) {
            return;
        }
        $state = VmPDO::state($receiver);
        $ht = new HashTable();
        $c0 = new Variable();
        $c0->string($state->errorCode);
        $ht->add('0', $c0);
        $c1 = new Variable();
        if (null === $state->errorDriverCode) {
            $c1->null();
        } else {
            $c1->int($state->errorDriverCode);
        }
        $ht->add('1', $c1);
        $c2 = new Variable();
        if (null === $state->errorMessage) {
            $c2->null();
        } else {
            $c2->string($state->errorMessage);
        }
        $ht->add('2', $c2);
        $frame->returnVar->array($ht);
    }
}

/** PDO::sqliteCreateFunction — pdo_sqlite (#19863); shares expand with SQLite3::createFunction. */
final class PDOSqliteCreateFunction extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('sqliteCreateFunction');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::sqliteCreateFunction()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'PDO::sqliteCreateFunction() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'PDO::sqliteCreateFunction', 0, 'name');
        if ('' === $name) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDO::sqliteCreateFunction() requires a VM context');
        }
        $callback = $frame->calledArgs[2]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $callback)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('PDO::sqliteCreateFunction'));
        }
        $argc = -1;
        if (\count($frame->calledArgs) >= 4) {
            $argc = $this->intArg($frame->calledArgs[3], 'PDO::sqliteCreateFunction', 2, 'numArgs', -1);
        }
        [$pinned, $closureState] = SplIteratorSupport::pinCallback($callback);
        $state = VmPDO::state($receiver);
        $state->functions[strtolower($name)] = [
            'callback' => $pinned,
            'closure' => $closureState,
            'argc' => $argc,
            'ctx' => $ctx,
        ];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/**
 * PDO::sqliteCreateAggregate — method present (pdo_sqlite.stub.php; #19863).
 * Full step/finalize UDF needs FFI::callback (PHP ≥ 8.3); registration succeeds for method_exists.
 */
final class PDOSqliteCreateAggregate extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('sqliteCreateAggregate');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::sqliteCreateAggregate()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'PDO::sqliteCreateAggregate() expects at least 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        // Validate name + callables so TypeErrors match Zend; execution of aggregates deferred.
        $name = $this->stringArg($frame->calledArgs[1], 'PDO::sqliteCreateAggregate', 0, 'name');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDO::sqliteCreateAggregate() requires a VM context');
        }
        if (!VmCallable::isCallable($ctx, $frame->calledArgs[2]->resolveIndirect())
            || !VmCallable::isCallable($ctx, $frame->calledArgs[3]->resolveIndirect())) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('PDO::sqliteCreateAggregate'));
        }
        if ('' === $name) {
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

/** Pdo\Mysql::getWarningCount() — stub until native mysql factory (#20548). */
final class PDOMysqlGetWarningCount extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('getWarningCount');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, 'Pdo\\Mysql::getWarningCount()');
        throw new \PDOException('could not find driver');
    }
}

/** Shared: Pdo\Pgsql methods require a live pgsql handle (not wired yet; #20548). */
abstract class PDOPgsqlUnimplementedMethod extends PdoClassMethod
{
    public function execute(Frame $frame): void
    {
        $this->receiver($frame, 'Pdo\\Pgsql::'.$this->getName().'()');
        throw new \PDOException('could not find driver');
    }
}

final class PDOPgsqlEscapeIdentifier extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('escapeIdentifier');
    }
}

final class PDOPgsqlCopyFromArray extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('copyFromArray');
    }
}

final class PDOPgsqlCopyFromFile extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('copyFromFile');
    }
}

final class PDOPgsqlCopyToArray extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('copyToArray');
    }
}

final class PDOPgsqlCopyToFile extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('copyToFile');
    }
}

final class PDOPgsqlLobCreate extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('lobCreate');
    }
}

final class PDOPgsqlLobOpen extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('lobOpen');
    }
}

final class PDOPgsqlLobUnlink extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('lobUnlink');
    }
}

final class PDOPgsqlGetNotify extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('getNotify');
    }
}

final class PDOPgsqlGetPid extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('getPid');
    }
}

final class PDOPgsqlSetNoticeCallback extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('setNoticeCallback');
    }
}
