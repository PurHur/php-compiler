<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExceptionSupport;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * mysqli VM class (php-src ext/mysqli/mysqli.c; #3435).
 *
 * Phase-1: host bridge when ext/mysqli loaded on harness PHP; stub API otherwise.
 * Live connections use host \mysqli via reflection; without ext/mysqli on the host,
 * mysqli_connect() returns false and sets connect_errno/connect_error.
 */
final class VmMysqli
{
    public const CLASS_LC = 'mysqli';

    /** @var array<int, MysqliState> */
    private static array $store = [];

    private static int $connectErrno = 0;

    private static string $connectError = '';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['query'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('mysqli');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new MysqliConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'query' => new MysqliQuery(),
            'close' => new MysqliClose(),
            'real_escape_string' => new MysqliRealEscapeString(),
            'prepare' => new MysqliPrepare(),
            'autocommit' => new MysqliAutocommit(),
            'begin_transaction' => new MysqliBeginTransaction(),
            'commit' => new MysqliCommit(),
            'rollback' => new MysqliRollback(),
            'savepoint' => new MysqliSavepoint(),
            'release_savepoint' => new MysqliReleaseSavepoint(),
            'refresh' => new MysqliRefresh(),
            'get_connection_stats' => new MysqliGetConnectionStats(),
            'real_connect' => new MysqliRealConnect(),
            'options' => new MysqliOptions(),
            'set_charset' => new MysqliSetCharset(),
            'multi_query' => new MysqliMultiQuery(),
            'next_result' => new MysqliNextResult(),
            'store_result' => new MysqliStoreResult(),
        ];
        foreach ($methods as $name => $method) {
            $lcName = strtolower($name);
            $entry->methods[$lcName] = $method;
            $entry->methodVisibility[$lcName] = $pub;
            if ($lcName !== $name) {
                $entry->methodNames[$lcName] = $name;
            }
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function connect(
        ?string $hostname,
        ?string $username,
        ?string $password,
        ?string $database,
        ?int $port,
        ?string $socket
    ): ?ObjectEntry {
        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            self::$connectErrno = 2002;
            self::$connectError = 'mysqli extension not available on host PHP';

            return null;
        }

        $hostname = $hostname ?? ini_get('mysqli.default_host') ?: '127.0.0.1';
        $username = $username ?? ini_get('mysqli.default_user') ?: '';
        $password = $password ?? ini_get('mysqli.default_pw') ?: '';
        $database = $database ?? '';
        $port = $port ?? (int) (ini_get('mysqli.default_port') ?: 3306);
        $socket = $socket ?? ini_get('mysqli.default_socket') ?: '';

        try {
            $native = @new \mysqli($hostname, $username, $password, $database, $port, $socket);
        } catch (\mysqli_sql_exception $e) {
            self::$connectErrno = $e->getCode();
            self::$connectError = $e->getMessage();

            return null;
        }

        if ($native->connect_errno) {
            self::$connectErrno = $native->connect_errno;
            self::$connectError = $native->connect_error ?? 'Unknown error';

            return null;
        }

        self::$connectErrno = 0;
        self::$connectError = '';

        return self::wrapNative($native);
    }

    private static function wrapNative(\mysqli $native): ObjectEntry
    {
        $ctx = null;
        foreach (self::$store as $s) {
            if (null !== $s->ctx) {
                $ctx = $s->ctx;
                break;
            }
        }
        if (null === $ctx) {
            throw new \LogicException('No VM context available for mysqli');
        }
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliState();
        $state->native = $native;
        $state->ctx = $ctx;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    public static function wrapNativeWithContext(Context $ctx, \mysqli $native): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliState();
        $state->native = $native;
        $state->ctx = $ctx;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    public static function setConnectError(int $errno, string $error): void
    {
        self::$connectErrno = $errno;
        self::$connectError = $error;
    }

    public static function connectErrno(): int
    {
        return self::$connectErrno;
    }

    public static function connectError(): string
    {
        return self::$connectError;
    }

    public static function attachState(ObjectEntry $entry, MysqliState $state): void
    {
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
    }

    public static function state(ObjectEntry $entry, ?Context $ctx = null): MysqliState
    {
        if (!isset(self::$store[$entry->id])) {
            if (null !== $ctx) {
                self::throwUninitialized();
            }
            throw new \LogicException('mysqli object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireNative(ObjectEntry $entry, Context $ctx): \mysqli
    {
        $state = self::state($entry, $ctx);
        if (null === $state->native) {
            throw new \LogicException('mysqli connection is closed');
        }

        return $state->native;
    }

    /** @return never */
    private static function throwUninitialized(): void
    {
        throw new \mysqli_sql_exception('mysqli object is not fully initialized', 0);
    }

    public static function destroyState(ObjectEntry $entry): void
    {
        unset(self::$store[$entry->id]);
    }

    public static function assignRow(Variable $returnVar, array $row): void
    {
        $ht = new HashTable();
        foreach ($row as $key => $item) {
            $slot = new Variable();
            if (null === $item) {
                $slot->null();
            } elseif (\is_int($item)) {
                $slot->int($item);
            } elseif (\is_float($item)) {
                $slot->float($item);
            } else {
                $slot->string((string) $item);
            }
            $ht->add(\is_int($key) ? (string) $key : $key, $slot);
        }
        $returnVar->array($ht);
    }

    public static function initStore(Context $ctx): void
    {
        $sentinel = new MysqliState();
        $sentinel->ctx = $ctx;
        self::$store[-1] = $sentinel;
    }

    public static function autocommitOnLink(ObjectEntry $entry, Context $ctx, bool $mode): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->autocommit($mode);
    }

    public static function beginTransactionOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0, ?string $name = null): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->begin_transaction($flags, $name);
    }

    public static function commitOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0, ?string $name = null): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->commit($flags, $name);
    }

    public static function rollbackOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0, ?string $name = null): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->rollback($flags, $name);
    }

    public static function savepointOnLink(ObjectEntry $entry, Context $ctx, string $name): bool
    {
        $native = self::requireNative($entry, $ctx);
        if (method_exists($native, 'savepoint')) {
            return $native->savepoint($name);
        }

        return true === $native->query(self::savepointSql($name));
    }

    public static function releaseSavepointOnLink(ObjectEntry $entry, Context $ctx, string $name): bool
    {
        $native = self::requireNative($entry, $ctx);
        if (method_exists($native, 'release_savepoint')) {
            return $native->release_savepoint($name);
        }

        return true === $native->query(self::releaseSavepointSql($name));
    }

    private static function savepointSql(string $name): string
    {
        return 'SAVEPOINT `'.str_replace('`', '``', $name).'`';
    }

    private static function releaseSavepointSql(string $name): string
    {
        return 'RELEASE SAVEPOINT `'.str_replace('`', '``', $name).'`';
    }

    public static function refreshOnLink(ObjectEntry $entry, Context $ctx, int $options): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->refresh($options);
    }

    /** @return array<string, int|float|string|null> */
    public static function connectionStatsOnLink(ObjectEntry $entry, Context $ctx): array
    {
        $native = self::requireNative($entry, $ctx);
        if (!method_exists($native, 'get_connection_stats')) {
            return [];
        }
        $stats = $native->get_connection_stats();
        if (!\is_array($stats)) {
            return [];
        }

        return $stats;
    }

    public static function realConnectOnLink(
        ObjectEntry $entry,
        Context $ctx,
        ?string $hostname,
        ?string $username,
        ?string $password,
        ?string $database,
        ?int $port,
        ?string $socket,
        int $flags = 0
    ): bool {
        $native = self::requireNativeOrInit($entry, $ctx);
        $hostname = $hostname ?? ini_get('mysqli.default_host') ?: null;
        $username = $username ?? ini_get('mysqli.default_user') ?: null;
        $password = $password ?? ini_get('mysqli.default_pw') ?: null;
        $database = $database ?? null;
        $port = $port ?? (int) (ini_get('mysqli.default_port') ?: 3306);
        $socket = $socket ?? ini_get('mysqli.default_socket') ?: null;

        return $native->real_connect($hostname, $username, $password, $database, $port, $socket, $flags);
    }

    public static function optionsOnLink(ObjectEntry $entry, Context $ctx, int $option, mixed $value): bool
    {
        $native = self::requireNativeOrInit($entry, $ctx);

        return $native->options($option, $value);
    }

    public static function setCharsetOnLink(ObjectEntry $entry, Context $ctx, string $charset): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->set_charset($charset);
    }

    public static function multiQueryOnLink(ObjectEntry $entry, Context $ctx, string $query): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->multi_query($query);
    }

    public static function nextResultOnLink(ObjectEntry $entry, Context $ctx): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->next_result();
    }

    public static function storeResultOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0): ObjectEntry|bool
    {
        $native = self::requireNative($entry, $ctx);
        $result = $native->store_result($flags);
        if (false === $result) {
            return false;
        }

        return VmMysqliResult::wrap($ctx, $result);
    }

    public static function infoOnLink(ObjectEntry $entry, Context $ctx): ?string
    {
        $native = self::requireNative($entry, $ctx);
        $info = $native->info;

        return false === $info || null === $info ? null : (string) $info;
    }

    public static function statOnLink(ObjectEntry $entry, Context $ctx): ?string
    {
        $native = self::requireNative($entry, $ctx);
        $stat = $native->stat();
        if (false === $stat) {
            return null;
        }

        return (string) $stat;
    }

    public static function requireNativeOrInit(ObjectEntry $entry, Context $ctx): \mysqli
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('mysqli object has not been correctly initialized');
        }
        $state = self::$store[$entry->id];
        if (null === $state->native) {
            if (!MysqliExtensionPolicy::hasNativeDriver()) {
                throw new \LogicException('mysqli extension not available on host PHP');
            }
            $state->native = new \mysqli();
        }

        return $state->native;
    }
}

/** @internal */
final class MysqliState
{
    public ?\mysqli $native = null;

    public ?Context $ctx = null;
}

/** @internal — mysqli_result VM wrapper. */
final class VmMysqliResult
{
    public const CLASS_LC = 'mysqli_result';

    /** @var array<int, MysqliResultState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry('mysqli_result');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'fetch_assoc' => new MysqliResultFetchAssoc(),
            'fetch_array' => new MysqliResultFetchArray(),
            'fetch_row' => new MysqliResultFetchRow(),
            'free' => new MysqliResultFree(),
            'close' => new MysqliResultFree(),
            'free_result' => new MysqliResultFree(),
            'fetch_all' => new MysqliResultFetchAll(),
        ];
        foreach ($methods as $name => $method) {
            $lcName = strtolower($name);
            $entry->methods[$lcName] = $method;
            $entry->methodVisibility[$lcName] = $pub;
            if ($lcName !== $name) {
                $entry->methodNames[$lcName] = $name;
            }
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrap(Context $ctx, \mysqli_result $native): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli_result class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliResultState();
        $state->native = $native;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    public static function state(ObjectEntry $entry): MysqliResultState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('mysqli_result object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireNative(ObjectEntry $entry): \mysqli_result
    {
        $state = self::state($entry);
        if (null === $state->native) {
            throw new \LogicException('mysqli_result is freed');
        }

        return $state->native;
    }

    public static function destroyState(ObjectEntry $entry): void
    {
        unset(self::$store[$entry->id]);
    }
}

/** @internal */
final class MysqliResultState
{
    public ?\mysqli_result $native = null;
}

final class MysqliConstruct extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::__construct()');
        if ($receiver->constructed) {
            throw new \LogicException('mysqli object already initialized');
        }
        $argc = \count($frame->calledArgs) - 1;
        $hostname = $argc >= 1 ? $this->stringArgNullable($frame->calledArgs[1]) : null;
        $username = $argc >= 2 ? $this->stringArgNullable($frame->calledArgs[2]) : null;
        $password = $argc >= 3 ? $this->stringArgNullable($frame->calledArgs[3]) : null;
        $database = $argc >= 4 ? $this->stringArgNullable($frame->calledArgs[4]) : null;
        $port = $argc >= 5 ? $this->intArgNullable($frame->calledArgs[5]) : null;
        $socket = $argc >= 6 ? $this->stringArgNullable($frame->calledArgs[6]) : null;

        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli requires VM context');

        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            // No host ext/mysqli — mark object as constructed but with no native handle.
            // php-src: new mysqli() with no args returns an unconnected object;
            // with args and no driver it would throw mysqli_sql_exception.
            if ($argc > 0) {
                throw new \mysqli_sql_exception('mysqli extension not available on host PHP', 2002);
            }
            $receiver->constructed = true;

            return;
        }

        $hostname = $hostname ?? ini_get('mysqli.default_host') ?: '127.0.0.1';
        $username = $username ?? ini_get('mysqli.default_user') ?: '';
        $password = $password ?? ini_get('mysqli.default_pw') ?: '';
        $database = $database ?? '';
        $port = $port ?? (int) (ini_get('mysqli.default_port') ?: 3306);
        $socket = $socket ?? ini_get('mysqli.default_socket') ?: '';

        try {
            $native = @new \mysqli($hostname, $username, $password, $database, $port, $socket);
        } catch (\mysqli_sql_exception $e) {
            throw $e;
        }
        if ($native->connect_errno) {
            throw new \mysqli_sql_exception($native->connect_error ?? 'Connection error', $native->connect_errno);
        }

        $state = new MysqliState();
        $state->native = $native;
        $state->ctx = $ctx;
        VmMysqli::attachState($receiver, $state);
    }

    private function stringArgNullable(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }

    private function intArgNullable(Variable $var): ?int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toInt();
    }
}

final class MysqliQuery extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::query() expects at least 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'mysqli::query', 0, 'query');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::query() requires VM context');
        $native = VmMysqli::requireNative($receiver, $ctx);
        $result = $native->query($sql);
        if (null === $frame->returnVar) {
            return;
        }
        if (true === $result) {
            $frame->returnVar->bool(true);
        } elseif (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $ctx = VmMysqli::state($receiver)->ctx ?? throw new \LogicException('No VM context');
            $frame->returnVar->object(VmMysqliResult::wrap($ctx, $result));
        }
    }
}

final class MysqliClose extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::close()');
        $state = VmMysqli::state($receiver);
        if (null !== $state->native) {
            $state->native->close();
            $state->native = null;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class MysqliRealEscapeString extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('real_escape_string');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::real_escape_string()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::real_escape_string() expects exactly 1 argument, 0 given');
        }
        $str = $this->stringArg($frame->calledArgs[1], 'mysqli::real_escape_string', 0, 'string');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::real_escape_string() requires VM context');
        $native = VmMysqli::requireNative($receiver, $ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($native->real_escape_string($str));
        }
    }
}

final class MysqliPrepare extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('prepare');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::prepare()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::prepare() expects exactly 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'mysqli::prepare', 0, 'query');
        $result = VmMysqliStmt::prepareOnLink($receiver, $sql);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

final class MysqliAutocommit extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('autocommit');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::autocommit()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::autocommit() expects exactly 1 argument, 0 given');
        }
        $mode = $this->boolArg($frame->calledArgs[1], 'mysqli::autocommit', 0, 'mode');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::autocommit() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::autocommitOnLink($receiver, $ctx, $mode));
        }
    }

    private function boolArg(Variable $var, string $label, int $index, string $paramName): bool
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return 0 !== $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return false;
        }

        return (bool) $resolved->toString();
    }
}

final class MysqliBeginTransaction extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('begin_transaction');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::begin_transaction()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::begin_transaction', 0, 'flags', 0)
            : 0;
        $name = \count($frame->calledArgs) >= 3
            ? $this->optionalStringArg($frame->calledArgs[2])
            : null;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::begin_transaction() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::beginTransactionOnLink($receiver, $ctx, $flags, $name));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliCommit extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('commit');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::commit()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::commit', 0, 'flags', 0)
            : 0;
        $name = \count($frame->calledArgs) >= 3
            ? $this->optionalStringArg($frame->calledArgs[2])
            : null;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::commit() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::commitOnLink($receiver, $ctx, $flags, $name));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliRollback extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('rollback');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::rollback()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::rollback', 0, 'flags', 0)
            : 0;
        $name = \count($frame->calledArgs) >= 3
            ? $this->optionalStringArg($frame->calledArgs[2])
            : null;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::rollback() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::rollbackOnLink($receiver, $ctx, $flags, $name));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliSavepoint extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('savepoint');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::savepoint()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::savepoint() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'mysqli::savepoint', 0, 'name');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::savepoint() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::savepointOnLink($receiver, $ctx, $name));
        }
    }
}

final class MysqliReleaseSavepoint extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('release_savepoint');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::release_savepoint()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::release_savepoint() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'mysqli::release_savepoint', 0, 'name');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::release_savepoint() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::releaseSavepointOnLink($receiver, $ctx, $name));
        }
    }
}

final class MysqliRefresh extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('refresh');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::refresh()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::refresh() expects exactly 1 argument, 0 given');
        }
        $options = $this->intArg($frame->calledArgs[1], 'mysqli::refresh', 0, 'options', 0);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::refresh() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::refreshOnLink($receiver, $ctx, $options));
        }
    }
}

final class MysqliGetConnectionStats extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_connection_stats');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_connection_stats()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_connection_stats() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqli::assignRow($frame->returnVar, VmMysqli::connectionStatsOnLink($receiver, $ctx));
    }
}

final class MysqliRealConnect extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('real_connect');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::real_connect()');
        $argc = \count($frame->calledArgs) - 1;
        $hostname = $argc >= 1 ? $this->optionalStringArg($frame->calledArgs[1]) : null;
        $username = $argc >= 2 ? $this->optionalStringArg($frame->calledArgs[2]) : null;
        $password = $argc >= 3 ? $this->optionalStringArg($frame->calledArgs[3]) : null;
        $database = $argc >= 4 ? $this->optionalStringArg($frame->calledArgs[4]) : null;
        $port = $argc >= 5 ? $this->intArg($frame->calledArgs[5], 'mysqli::real_connect', 4, 'port', 0) : null;
        $socket = $argc >= 6 ? $this->optionalStringArg($frame->calledArgs[6]) : null;
        $flags = $argc >= 7 ? $this->intArg($frame->calledArgs[7], 'mysqli::real_connect', 6, 'flags', 0) : 0;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::real_connect() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::realConnectOnLink(
                $receiver,
                $ctx,
                $hostname,
                $username,
                $password,
                $database,
                $port,
                $socket,
                $flags
            ));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliOptions extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('options');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::options()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('mysqli::options() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given');
        }
        $option = $this->intArg($frame->calledArgs[1], 'mysqli::options', 0, 'option');
        $value = MysqliProceduralLink::optionValue($frame->calledArgs[2]);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::options() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::optionsOnLink($receiver, $ctx, $option, $value));
        }
    }
}

final class MysqliSetCharset extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('set_charset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::set_charset()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::set_charset() expects exactly 1 argument, 0 given');
        }
        $charset = $this->stringArg($frame->calledArgs[1], 'mysqli::set_charset', 0, 'charset');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::set_charset() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::setCharsetOnLink($receiver, $ctx, $charset));
        }
    }
}

final class MysqliMultiQuery extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('multi_query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::multi_query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::multi_query() expects exactly 1 argument, 0 given');
        }
        $query = $this->stringArg($frame->calledArgs[1], 'mysqli::multi_query', 0, 'query');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::multi_query() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::multiQueryOnLink($receiver, $ctx, $query));
        }
    }
}

final class MysqliNextResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('next_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::next_result()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::next_result() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::nextResultOnLink($receiver, $ctx));
        }
    }
}

final class MysqliStoreResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('store_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::store_result()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::store_result', 0, 'flags', 0)
            : 0;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::store_result() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqli::storeResultOnLink($receiver, $ctx, $flags);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

final class MysqliResultFetchAssoc extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_assoc');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_assoc()');
        $native = VmMysqliResult::requireNative($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $row = $native->fetch_assoc();
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }
}

final class MysqliResultFetchArray extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_array');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_array()');
        $native = VmMysqliResult::requireNative($receiver);
        $mode = MysqliConstants::MYSQLI_BOTH;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'mysqli_result::fetch_array', 0, 'mode', MysqliConstants::MYSQLI_BOTH);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $row = match ($mode) {
            MysqliConstants::MYSQLI_ASSOC => $native->fetch_assoc(),
            MysqliConstants::MYSQLI_NUM => $native->fetch_row(),
            default => $native->fetch_array(\MYSQLI_BOTH),
        };
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }
}

final class MysqliResultFetchRow extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_row');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_row()');
        $native = VmMysqliResult::requireNative($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $row = $native->fetch_row();
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }
}

final class MysqliResultFetchAll extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_all');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_all()');
        $native = VmMysqliResult::requireNative($receiver);
        $mode = MysqliConstants::MYSQLI_NUM;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'mysqli_result::fetch_all', 0, 'mode', MysqliConstants::MYSQLI_NUM);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $nativeMode = match ($mode) {
            MysqliConstants::MYSQLI_ASSOC => \MYSQLI_ASSOC,
            MysqliConstants::MYSQLI_BOTH => \MYSQLI_BOTH,
            default => \MYSQLI_NUM,
        };
        $rows = $native->fetch_all($nativeMode);
        $ht = new HashTable();
        foreach ($rows as $i => $row) {
            $slot = new Variable();
            VmMysqli::assignRow($slot, $row);
            $ht->add((string) $i, $slot);
        }
        $frame->returnVar->array($ht);
    }
}

final class MysqliResultFree extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('free');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::free()');
        $state = VmMysqliResult::state($receiver);
        if (null !== $state->native) {
            $state->native->free();
            $state->native = null;
        }
    }
}
