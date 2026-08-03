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
use PHPCompiler\ext\pgsql\VmPgsqlNative;
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
            self::registerSqliteExtensionMethods($ctx->classes[self::CLASS_LC]);
            self::registerPgsqlExtensionMethods($ctx->classes[self::CLASS_LC]);

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
        $entry->methodDeclaringClassLc['__construct'] = self::CLASS_LC;

        $methods = [
            'exec' => new PDOExec(),
            'prepare' => new PDOPrepare(),
            'query' => new PDOQuery(),
            'setattribute' => new PDOSetAttribute(),
            'getattribute' => new PDOGetAttribute(),
            'getavailabledrivers' => new PDOGetAvailableDrivers(),
            'lastinsertid' => new PDOLastInsertId(),
            'quote' => new PDOQuote(),
            'begintransaction' => new PDOBeginTransaction(),
            'commit' => new PDOCommit(),
            'rollback' => new PDORollBack(),
            'intransaction' => new PDOInTransaction(),
            'errorcode' => new PDOErrorCode(),
            'errorinfo' => new PDOErrorInfo(),
        ];
        // PHP 8.4+ only — Zend 8.2 stubs omit PDO::connect (#22600, re-#20529).
        if (PdoExtensionPolicy::advertisesConnect()) {
            $methods['connect'] = new PDOConnect();
        }
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
        $entry->methodVisibility['getavailabledrivers'] = CfgFunc::FLAG_STATIC | $pub;
        if (isset($entry->methods['connect'])) {
            $entry->methodVisibility['connect'] = CfgFunc::FLAG_STATIC | $pub;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
        self::registerDriverSubclasses($ctx);
        // Legacy PDO::sqlite* / pgsql* after subclasses so method_exists on Pdo\* matches Zend (#21552).
        self::registerSqliteExtensionMethods($entry);
        self::registerPgsqlExtensionMethods($entry);
    }

    /**
     * Legacy PDO::sqliteCreate* driver methods (php-src sqlite_driver.stub.php PDO_SQLite_Ext; #21552).
     *
     * Registered on PDO after driver subclasses so they stay parent-only (methodNotInherited),
     * matching Zend's post-inheritance extension-method registration. Pdo\Sqlite gets its own copy.
     */
    public static function registerSqliteExtensionMethods(ClassEntry $entry): void
    {
        if (!PdoExtensionPolicy::advertisesSqliteDriver()) {
            return;
        }
        if (isset($entry->methods['sqlitecreatefunction'])) {
            $entry->methodNotInherited['sqlitecreatefunction'] = true;
            $entry->methodNotInherited['sqlitecreateaggregate'] = true;
            $entry->methodNotInherited['sqlitecreatecollation'] = true;

            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'sqlitecreatefunction' => [new PDOSqliteCreateFunction(), 'sqliteCreateFunction'],
            'sqlitecreateaggregate' => [new PDOSqliteCreateAggregate(), 'sqliteCreateAggregate'],
            'sqlitecreatecollation' => [new PDOSqliteCreateCollation(), 'sqliteCreateCollation'],
        ];
        foreach ($methods as $lc => [$method, $display]) {
            $entry->methods[$lc] = $method;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $display;
            $entry->methodDeclaringClassLc[$lc] = self::CLASS_LC;
            $entry->methodNotInherited[$lc] = true;
        }
    }

    /**
     * Legacy PDO::pgsql* driver methods (php-src pgsql_driver.stub.php PDO_PGSql_Ext; #20566).
     *
     * Registered on PDO when the pgsql driver is advertised ({@see PdoExtensionPolicy::advertisesPgsqlDriver()})
     * or the PHP 8.4 {@see Pdo\Pgsql} surface ships ({@see PdoExtensionPolicy::advertisesPgsqlSubclass()})
     * so method_exists(PDO::class, 'pgsqlGetPid') matches Zend / #20566. Live libpq
     * I/O remains #3741 — calls without a pgsql handle throw like the Pdo\Pgsql stubs.
     * Marked methodNotInherited so Pdo\Mysql / Pdo\Sqlite do not advertise them (#21552).
     */
    public static function registerPgsqlExtensionMethods(ClassEntry $entry): void
    {
        if (!PdoExtensionPolicy::advertisesPgsqlDriver()
            && !PdoExtensionPolicy::advertisesPgsqlSubclass()
        ) {
            return;
        }
        if (isset($entry->methods['pgsqlgetpid'])) {
            foreach ([
                'pgsqlcopyfromarray',
                'pgsqlcopyfromfile',
                'pgsqlcopytoarray',
                'pgsqlcopytofile',
                'pgsqllobcreate',
                'pgsqllobopen',
                'pgsqllobunlink',
                'pgsqlgetnotify',
                'pgsqlgetpid',
            ] as $lc) {
                $entry->methodNotInherited[$lc] = true;
            }

            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'pgsqlcopyfromarray' => [new PDOPgsqlCopyFromArrayLegacy(), 'pgsqlCopyFromArray'],
            'pgsqlcopyfromfile' => [new PDOPgsqlCopyFromFileLegacy(), 'pgsqlCopyFromFile'],
            'pgsqlcopytoarray' => [new PDOPgsqlCopyToArrayLegacy(), 'pgsqlCopyToArray'],
            'pgsqlcopytofile' => [new PDOPgsqlCopyToFileLegacy(), 'pgsqlCopyToFile'],
            'pgsqllobcreate' => [new PDOPgsqlLobCreateLegacy(), 'pgsqlLOBCreate'],
            'pgsqllobopen' => [new PDOPgsqlLobOpenLegacy(), 'pgsqlLOBOpen'],
            'pgsqllobunlink' => [new PDOPgsqlLobUnlinkLegacy(), 'pgsqlLOBUnlink'],
            'pgsqlgetnotify' => [new PDOPgsqlGetNotifyLegacy(), 'pgsqlGetNotify'],
            'pgsqlgetpid' => [new PDOPgsqlGetPidLegacy(), 'pgsqlGetPid'],
        ];
        foreach ($methods as $lc => [$method, $display]) {
            $entry->methods[$lc] = $method;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $display;
            $entry->methodDeclaringClassLc[$lc] = self::CLASS_LC;
            $entry->methodNotInherited[$lc] = true;
        }
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
     *
     * Inherits PDO::__construct so `new Pdo\Sqlite($dsn)` runs the same DSN factory as
     * `PDO::__construct` / `PDO::connect` (php-src pdo_dbh.c; #21096).
     * Owns sqliteCreate* (legacy names) — not inherited from PDO_*_Ext (#21552).
     */
    public static function registerSqliteSubclass(Context $ctx): void
    {
        if (!PdoExtensionPolicy::advertisesSqliteSubclass()) {
            return;
        }
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        if (isset($ctx->classes[self::SQLITE_CLASS_LC])) {
            self::inheritPdoConstructor($ctx->classes[self::SQLITE_CLASS_LC], $ctx->classes[self::CLASS_LC]);
            self::installSqliteSubclassMethods($ctx->classes[self::SQLITE_CLASS_LC]);

            return;
        }
        $sqlite = new ClassEntry(self::SQLITE_CLASS_NAME);
        $sqlite->isInternal = true;
        $sqlite->parentLc = self::CLASS_LC;
        self::inheritPdoConstructor($sqlite, $ctx->classes[self::CLASS_LC]);
        self::installSqliteSubclassMethods($sqlite);
        $ctx->classes[self::SQLITE_CLASS_LC] = $sqlite;
    }

    /** Attach sqliteCreate* on Pdo\Sqlite (php-src keeps create* on subclass; we keep legacy names #19863/#21552). */
    private static function installSqliteSubclassMethods(ClassEntry $sqlite): void
    {
        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'sqlitecreatefunction' => [new PDOSqliteCreateFunction(), 'sqliteCreateFunction'],
            'sqlitecreateaggregate' => [new PDOSqliteCreateAggregate(), 'sqliteCreateAggregate'],
            'sqlitecreatecollation' => [new PDOSqliteCreateCollation(), 'sqliteCreateCollation'],
        ];
        foreach ($methods as $lc => [$method, $display]) {
            $sqlite->methods[$lc] = $method;
            $sqlite->methodVisibility[$lc] = $pub;
            $sqlite->methodNames[$lc] = $display;
            $sqlite->methodDeclaringClassLc[$lc] = self::SQLITE_CLASS_LC;
            unset($sqlite->methodNotInherited[$lc]);
        }
        self::stripForeignDriverMethods($sqlite, [
            'pgsqlcopyfromarray',
            'pgsqlcopyfromfile',
            'pgsqlcopytoarray',
            'pgsqlcopytofile',
            'pgsqllobcreate',
            'pgsqllobopen',
            'pgsqllobunlink',
            'pgsqlgetnotify',
            'pgsqlgetpid',
            'getwarningcount',
        ]);
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
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        if (isset($ctx->classes[self::MYSQL_CLASS_LC])) {
            self::inheritPdoConstructor($ctx->classes[self::MYSQL_CLASS_LC], $ctx->classes[self::CLASS_LC]);
            self::finalizeMysqlSubclassMethods($ctx->classes[self::MYSQL_CLASS_LC]);

            return;
        }
        $mysql = new ClassEntry(self::MYSQL_CLASS_NAME);
        $mysql->isInternal = true;
        $mysql->parentLc = self::CLASS_LC;
        self::inheritPdoConstructor($mysql, $ctx->classes[self::CLASS_LC]);
        foreach (PdoMysqlConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $mysql->constants[$name] = $const;
            $mysql->constNames[$name] = PdoMysqlConstants::CLASS_CONSTANT_NAMES[$name];
        }
        self::finalizeMysqlSubclassMethods($mysql);
        $ctx->classes[self::MYSQL_CLASS_LC] = $mysql;
    }

    private static function finalizeMysqlSubclassMethods(ClassEntry $mysql): void
    {
        $pub = CfgFunc::FLAG_PUBLIC;
        $mysql->methods['getwarningcount'] = new PDOMysqlGetWarningCount();
        $mysql->methodVisibility['getwarningcount'] = $pub;
        $mysql->methodNames['getwarningcount'] = 'getWarningCount';
        $mysql->methodDeclaringClassLc['getwarningcount'] = self::MYSQL_CLASS_LC;
        self::stripForeignDriverMethods($mysql, [
            'sqlitecreatefunction',
            'sqlitecreateaggregate',
            'sqlitecreatecollation',
            'pgsqlcopyfromarray',
            'pgsqlcopyfromfile',
            'pgsqlcopytoarray',
            'pgsqlcopytofile',
            'pgsqllobcreate',
            'pgsqllobopen',
            'pgsqllobunlink',
            'pgsqlgetnotify',
            'pgsqlgetpid',
        ]);
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
        if (!isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        if (isset($ctx->classes[self::PGSQL_CLASS_LC])) {
            self::inheritPdoConstructor($ctx->classes[self::PGSQL_CLASS_LC], $ctx->classes[self::CLASS_LC]);
            self::finalizePgsqlSubclassMethods($ctx->classes[self::PGSQL_CLASS_LC]);

            return;
        }
        $pgsql = new ClassEntry(self::PGSQL_CLASS_NAME);
        $pgsql->isInternal = true;
        $pgsql->parentLc = self::CLASS_LC;
        self::inheritPdoConstructor($pgsql, $ctx->classes[self::CLASS_LC]);
        foreach (PdoPgsqlConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $pgsql->constants[$name] = $const;
            $pgsql->constNames[$name] = PdoPgsqlConstants::CLASS_CONSTANT_NAMES[$name];
        }
        self::finalizePgsqlSubclassMethods($pgsql);
        $ctx->classes[self::PGSQL_CLASS_LC] = $pgsql;
    }

    private static function finalizePgsqlSubclassMethods(ClassEntry $pgsql): void
    {
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
            $pgsql->methodDeclaringClassLc[$lc] = self::PGSQL_CLASS_LC;
        }
        self::stripForeignDriverMethods($pgsql, [
            'sqlitecreatefunction',
            'sqlitecreateaggregate',
            'sqlitecreatecollation',
            'getwarningcount',
        ]);
    }

    /**
     * Remove cross-driver methods that may have been copied before methodNotInherited existed (#21552).
     *
     * @param list<string> $methodLcs
     */
    private static function stripForeignDriverMethods(ClassEntry $child, array $methodLcs): void
    {
        foreach ($methodLcs as $lc) {
            unset(
                $child->methods[$lc],
                $child->methodVisibility[$lc],
                $child->methodNames[$lc],
                $child->methodDeclaringClassLc[$lc],
                $child->methodDeprecated[$lc],
                $child->methodNotInherited[$lc]
            );
        }
    }

    /**
     * Wire parent PDO::__construct + inherited instance methods onto a driver subclass (#21096).
     *
     * Builtin subclasses register before {@see VM::inheritFromParent} runs, so NEW would
     * otherwise skip construction and AOT/JIT method tables would miss PDO::exec/query/….
     * Preserve {@see ClassEntry::$methodDeclaringClassLc} so ReflectionMethod::getDeclaringClass()
     * reports PDO (php-src zend_inheritance / reflection).
     * Skip {@see ClassEntry::$methodNotInherited} PDO_*_Ext methods (#21552).
     */
    private static function inheritPdoConstructor(ClassEntry $child, ClassEntry $pdo): void
    {
        foreach ($pdo->methods as $name => $method) {
            if (isset($child->methods[$name])) {
                continue;
            }
            $vis = $pdo->methodVisibility[$name] ?? CfgFunc::FLAG_PUBLIC;
            // Private methods are not inherited (Zend zend_inheritance).
            if (($vis & CfgFunc::FLAG_PRIVATE) !== 0) {
                continue;
            }
            if (isset($pdo->methodNotInherited[$name])) {
                continue;
            }
            $child->methods[$name] = $method;
            $child->methodVisibility[$name] = $vis;
            if (isset($pdo->methodDeclaringClassLc[$name])) {
                $child->methodDeclaringClassLc[$name] = $pdo->methodDeclaringClassLc[$name];
            } else {
                $child->methodDeclaringClassLc[$name] = self::CLASS_LC;
            }
            if (isset($pdo->methodDeprecated[$name])) {
                $child->methodDeprecated[$name] = $pdo->methodDeprecated[$name];
            }
            $child->methodNames[$name] = $pdo->methodNames[$name] ?? $name;
        }
        if (null === $child->constructor && null !== $pdo->constructor) {
            $child->constructor = $pdo->constructor;
        }
        if (null === $child->destructor && null !== $pdo->destructor) {
            $child->destructor = $pdo->destructor;
        }
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
     * Allocate and open a PDO (or driver subclass) handle from a DSN (#20529, #20548, #26140).
     *
     * mysql: throws "could not find driver" until a native factory lands. pgsql: follows
     * {@see PdoExtensionPolicy::advertisesPgsqlDriver()} — connection errors are OK when
     * the driver is advertised; "could not find driver" only when withheld.
     */
    public static function connect(Context $ctx, string $dsn, ?string $user = null, ?string $password = null): ObjectEntry
    {
        $driver = self::dsnDriverPrefix($dsn);
        if ('mysql' === $driver) {
            throw new \PDOException('could not find driver');
        }
        if ('pgsql' === $driver) {
            $class = $ctx->classes[self::PGSQL_CLASS_LC] ?? $ctx->classes[self::CLASS_LC] ?? null;
            if (null === $class) {
                throw new \LogicException('PDO is not registered in this compiler build');
            }
            $entry = new ObjectEntry($class);
            self::initPgsqlObject($entry, $dsn, $user, $password);

            return $entry;
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
     * Driver name list for PDO::getAvailableDrivers() / pdo_drivers() (php-src ext/pdo/pdo.c; #20239, #26140).
     */
    public static function availableDriversHashTable(): HashTable
    {
        $ht = new HashTable();
        $i = 0;
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            $slot = new Variable();
            $slot->string('sqlite');
            $ht->add((string) $i, $slot);
            ++$i;
        }
        if (PdoExtensionPolicy::advertisesPgsqlDriver()) {
            $slot = new Variable();
            $slot->string('pgsql');
            $ht->add((string) $i, $slot);
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
        // php-src pdo_find_driver — withhold sqlite when pdo_sqlite not advertised (#24523).
        if (!PdoExtensionPolicy::advertisesSqliteDriver()) {
            throw new \PDOException('could not find driver');
        }
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
        $state->driver = 'sqlite';
        $state->filename = $filename;
        $state->errMode = PdoConstants::ERRMODE_EXCEPTION;
        $state->fetchMode = PdoConstants::FETCH_BOTH;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
    }

    /**
     * Open a pgsql DSN via libpq when the driver is advertised (#26140).
     *
     * Connection refused / auth errors match Zend (not "could not find driver").
     * A successful connect stores the PGconn for later driver work (#3741).
     */
    public static function initPgsqlObject(
        ObjectEntry $entry,
        string $dsn,
        ?string $user = null,
        ?string $password = null
    ): void {
        if (!PdoExtensionPolicy::advertisesPgsqlDriver()) {
            throw new \PDOException('could not find driver');
        }
        if (!VmPgsqlNative::available()) {
            throw new \PDOException('SQLSTATE[08006] [7] could not connect to server');
        }
        $conninfo = self::pgsqlDsnToConninfo($dsn, $user, $password);
        $conn = VmPgsqlNative::connect($conninfo);
        if (null === $conn || VmPgsqlNative::CONNECTION_OK !== VmPgsqlNative::status($conn)) {
            $msg = trim(VmPgsqlNative::errorMessage($conn));
            if (null !== $conn) {
                VmPgsqlNative::finish($conn);
            }
            if ('' === $msg) {
                $msg = 'could not connect to server';
            }
            throw new \PDOException($msg);
        }
        $state = new PdoState();
        $state->pgsql = $conn;
        $state->driver = 'pgsql';
        $state->filename = $dsn;
        $state->errMode = PdoConstants::ERRMODE_EXCEPTION;
        $state->fetchMode = PdoConstants::FETCH_BOTH;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
    }

    /**
     * Convert PDO {@code pgsql:} DSN + user/password args to a libpq conninfo string.
     *
     * php-src ext/pdo_pgsql builds conninfo from DSN key=value pairs (semicolon-separated)
     * plus constructor user/password.
     */
    public static function pgsqlDsnToConninfo(string $dsn, ?string $user, ?string $password): string
    {
        if (!str_starts_with(strtolower($dsn), 'pgsql:')) {
            throw new \PDOException('could not find driver');
        }
        $params = substr($dsn, \strlen('pgsql:'));
        $parts = [];
        if ('' !== $params) {
            foreach (explode(';', $params) as $pair) {
                $pair = trim($pair);
                if ('' === $pair) {
                    continue;
                }
                $parts[] = $pair;
            }
        }
        if (null !== $user && '' !== $user) {
            $parts[] = 'user='.$user;
        }
        if (null !== $password && '' !== $password) {
            $parts[] = 'password='.$password;
        }

        return implode(' ', $parts);
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

    /**
     * php-src pdo_raise_impl_error() — SQLSTATE + supplemental (#20413).
     *
     * IM001 label: "Driver does not support this function" (ext/pdo/pdo_sqlstate.c).
     */
    public static function raiseImplError(PdoState $state, string $sqlState, string $supp): void
    {
        $label = 'IM001' === $sqlState
            ? 'Driver does not support this function'
            : 'General error';
        $message = 'SQLSTATE['.$sqlState.']: '.$label.': '.$supp;
        self::raise($state, $message, $sqlState);
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

    /**
     * Expand PDO sqliteCreateFunction / Aggregate / Collation in SQL
     * (#19863 / #22332; shares helpers with SQLite3).
     */
    public static function expandSql(ObjectEntry $entry, string $sql): string
    {
        $state = self::state($entry);
        if ([] !== $state->functions) {
            $sql = VmSqlite3Udf::expandSql($sql, $state->functions);
        }
        if ([] !== $state->aggregates && null !== $state->db) {
            $sql = VmSqlite3Udf::expandAggregates($state->db, $sql, $state->aggregates);
        }
        if ([] !== $state->collations && null !== $state->db) {
            $sql = VmSqlite3Udf::expandCollations($state->db, $sql, $state->collations);
        }

        return $sql;
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
            if (\is_array($item)) {
                // Nested lists for PDO::FETCH_NAMED duplicate columns (#25666).
                self::assignRow($slot, $item);
            } else {
                self::assignScalar($slot, $item);
            }
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

    /** @var \FFI\CData|null PGconn* when driver is pgsql (#26140 / #3741). */
    public $pgsql = null;

    /** Active PDO driver name (`sqlite` / `pgsql`). */
    public string $driver = 'sqlite';

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

    /**
     * PDO::sqliteCreateAggregate registrations (#22332; share expandAggregates with SQLite3).
     *
     * @var array<string, array{
     *     step: Variable,
     *     stepClosure: ?\PHPCompiler\VM\ClosureState,
     *     final: Variable,
     *     finalClosure: ?\PHPCompiler\VM\ClosureState,
     *     argc: int,
     *     ctx: \PHPCompiler\VM\Context
     * }>
     */
    public array $aggregates = [];

    /**
     * PDO::sqliteCreateCollation registrations (#22332).
     *
     * @var array<string, array{callback: Variable, closure: ?\PHPCompiler\VM\ClosureState, ctx: \PHPCompiler\VM\Context}>
     */
    public array $collations = [];
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
        $user = null;
        $password = null;
        if (\count($frame->calledArgs) >= 3) {
            $userVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $userVar->type) {
                $user = $this->stringArg($frame->calledArgs[2], 'PDO::__construct', 1, 'username');
            }
        }
        if (\count($frame->calledArgs) >= 4) {
            $passVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $passVar->type) {
                $password = $this->stringArg($frame->calledArgs[3], 'PDO::__construct', 2, 'password');
            }
        }
        $driver = VmPDO::dsnDriverPrefix($dsn);
        if ('pgsql' === $driver) {
            VmPDO::initPgsqlObject($receiver, $dsn, $user, $password);

            return;
        }
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
        $user = null;
        $password = null;
        if ($userArgc >= 2) {
            $userVar = $frame->calledArgs[$argOffset + 1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $userVar->type) {
                $user = $this->stringArg($frame->calledArgs[$argOffset + 1], 'PDO::connect', 1, 'username');
            }
        }
        if ($userArgc >= 3) {
            $passVar = $frame->calledArgs[$argOffset + 2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $passVar->type) {
                $password = $this->stringArg($frame->calledArgs[$argOffset + 2], 'PDO::connect', 2, 'password');
            }
        }
        // options ignored for sqlite/pgsql subset (same as __construct).
        $frame->returnVar->object(VmPDO::connect($ctx, $dsn, $user, $password));
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
        // php-src pdo_dbh_attribute_set + pdo_sqlite_set_attr (#20413): only a small
        // PDO-level set is honored on sqlite; ATTR_EMULATE_PREPARES is not sticky.
        $ok = true;
        if (PdoConstants::ATTR_ERRMODE === $attr) {
            $state->errMode = $value;
        } elseif (PdoConstants::ATTR_DEFAULT_FETCH_MODE === $attr) {
            $state->fetchMode = $value;
        } else {
            // sqlite driver set_attr returns false → setAttribute returns false (no IM001
            // unless the driver method pointer is NULL — pdo_dbh.c fail path).
            $ok = false;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
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
        // php-src PDO::getAttribute generic cases + pdo_sqlite_get_attribute (#20413).
        if (PdoConstants::ATTR_ERRMODE === $attr) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->int($state->errMode);
            }

            return;
        }
        if (PdoConstants::ATTR_DEFAULT_FETCH_MODE === $attr) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->int($state->fetchMode);
            }

            return;
        }
        if (PdoConstants::ATTR_DRIVER_NAME === $attr) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string('sqlite');
            }

            return;
        }
        // ATTR_EMULATE_PREPARES and other unsupported attrs → IM001 (pdo_dbh.c).
        VmPDO::raiseImplError($state, 'IM001', 'driver does not support that attribute');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false);
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

/** PDO::quote(string $string, int $type = PARAM_STR): string|false — sqlite %Q (#19861); null TypeError on 8.4 (#21080). */
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
 * PDO::sqliteCreateAggregate — pdo_sqlite (#19863 / #22332).
 * Stores step/final callables; SQL expansion evaluates SELECT agg(cols) FROM … (PHP 8.2 path).
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
        $name = $this->stringArg($frame->calledArgs[1], 'PDO::sqliteCreateAggregate', 0, 'name');
        if ('' === $name) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDO::sqliteCreateAggregate() requires a VM context');
        }
        $step = $frame->calledArgs[2]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $step)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('PDO::sqliteCreateAggregate'));
        }
        $final = $frame->calledArgs[3]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $final)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('PDO::sqliteCreateAggregate'));
        }
        $argc = -1;
        if (\count($frame->calledArgs) >= 5) {
            $argc = $this->intArg($frame->calledArgs[4], 'PDO::sqliteCreateAggregate', 3, 'numArgs', -1);
        }
        [$stepPinned, $stepClosure] = SplIteratorSupport::pinCallback($step);
        [$finalPinned, $finalClosure] = SplIteratorSupport::pinCallback($final);
        $state = VmPDO::state($receiver);
        $state->aggregates[strtolower($name)] = [
            'step' => $stepPinned,
            'stepClosure' => $stepClosure,
            'final' => $finalPinned,
            'finalClosure' => $finalClosure,
            'argc' => $argc,
            'ctx' => $ctx,
        ];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/**
 * PDO::sqliteCreateCollation — pdo_sqlite (#22332).
 * Stores callable; ORDER BY … COLLATE name expanded in PHP (no FFI::callback on 8.2).
 */
final class PDOSqliteCreateCollation extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('sqliteCreateCollation');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDO::sqliteCreateCollation()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'PDO::sqliteCreateCollation() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'PDO::sqliteCreateCollation', 0, 'name');
        if ('' === $name) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDO::sqliteCreateCollation() requires a VM context');
        }
        $callback = $frame->calledArgs[2]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $callback)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('PDO::sqliteCreateCollation'));
        }
        [$pinned, $closureState] = SplIteratorSupport::pinCallback($callback);
        $state = VmPDO::state($receiver);
        $state->collations[strtolower($name)] = [
            'callback' => $pinned,
            'closure' => $closureState,
            'ctx' => $ctx,
        ];
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

/**
 * Shared: Pdo\Pgsql / PDO::pgsql* methods need a live libpq handle (#20548, #20566).
 *
 * Until #3741 wires native pgsql connections, validate the receiver then throw the
 * same "could not find driver" path used by PDO::connect('pgsql:…').
 */
abstract class PDOPgsqlUnimplementedMethod extends PdoClassMethod
{
    protected function methodLabel(): string
    {
        $name = $this->getName();
        if (str_starts_with($name, 'pgsql') || str_starts_with($name, 'PDO::')) {
            return 'PDO::'.$name.'()';
        }

        return 'Pdo\\Pgsql::'.$name.'()';
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, $this->methodLabel());
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

/** Legacy PDO::pgsqlCopyFromArray — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlCopyFromArrayLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlCopyFromArray');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, $this->methodLabel());
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'PDO::pgsqlCopyFromArray() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $this->stringArg($frame->calledArgs[1], 'PDO::pgsqlCopyFromArray', 0, 'tableName');
        $rows = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $rows->type) {
            // Traversable accepted in php-src stub; array-only until libpq (#3741).
            if (Variable::TYPE_OBJECT !== $rows->type) {
                throw new \TypeError(\sprintf(
                    'PDO::pgsqlCopyFromArray(): Argument #2 ($rows) must be of type array|Traversable, %s given',
                    self::typeLabel($frame->calledArgs[2])
                ));
            }
        }
        if ($argc >= 3) {
            $this->stringArg($frame->calledArgs[3], 'PDO::pgsqlCopyFromArray', 2, 'separator');
        }
        if ($argc >= 4) {
            $this->stringArg($frame->calledArgs[4], 'PDO::pgsqlCopyFromArray', 3, 'nullAs');
        }
        if ($argc >= 5) {
            $fields = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $fields->type) {
                $this->stringArg($frame->calledArgs[5], 'PDO::pgsqlCopyFromArray', 4, 'fields');
            }
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'PDO::pgsqlCopyFromArray() expects at most 5 arguments, %d given',
                $argc
            ));
        }
        // Live COPY FROM needs libpq + Postgres (#3741); harness has no server by default.
        throw new \PDOException('could not find driver');
    }
}

/** Legacy PDO::pgsqlCopyFromFile — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlCopyFromFileLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlCopyFromFile');
    }
}

/** Legacy PDO::pgsqlCopyToArray — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlCopyToArrayLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlCopyToArray');
    }
}

/** Legacy PDO::pgsqlCopyToFile — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlCopyToFileLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlCopyToFile');
    }
}

/** Legacy PDO::pgsqlLOBCreate — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlLobCreateLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlLOBCreate');
    }
}

/** Legacy PDO::pgsqlLOBOpen — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlLobOpenLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlLOBOpen');
    }
}

/** Legacy PDO::pgsqlLOBUnlink — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlLobUnlinkLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlLOBUnlink');
    }
}

/** Legacy PDO::pgsqlGetNotify — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlGetNotifyLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlGetNotify');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, $this->methodLabel());
        $argc = \count($frame->calledArgs) - 1;
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'PDO::pgsqlGetNotify() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc >= 1) {
            $this->intArg($frame->calledArgs[1], 'PDO::pgsqlGetNotify', 0, 'fetchMode');
        }
        if ($argc >= 2) {
            $this->intArg($frame->calledArgs[2], 'PDO::pgsqlGetNotify', 1, 'timeoutMilliseconds');
        }
        // LISTEN/NOTIFY needs a live pgsql connection (#3741).
        throw new \PDOException('could not find driver');
    }
}

/** Legacy PDO::pgsqlGetPid — pgsql_driver.stub.php (#20566). */
final class PDOPgsqlGetPidLegacy extends PDOPgsqlUnimplementedMethod
{
    public function __construct()
    {
        parent::__construct('pgsqlGetPid');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, $this->methodLabel());
        $argc = \count($frame->calledArgs) - 1;
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'PDO::pgsqlGetPid() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        // Backend PID needs libpq PQbackendPID (#3741).
        throw new \PDOException('could not find driver');
    }
}
