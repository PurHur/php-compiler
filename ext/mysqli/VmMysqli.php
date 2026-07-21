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

    public static function state(ObjectEntry $entry): MysqliState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('mysqli object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireNative(ObjectEntry $entry): \mysqli
    {
        $state = self::state($entry);
        if (null === $state->native) {
            throw new \LogicException('mysqli connection is closed');
        }

        return $state->native;
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
                $ex = BuiltinExceptionSupport::materializeMysqliSqlException(
                    $ctx,
                    'mysqli extension not available on host PHP',
                    '',
                    0,
                    2002
                );
                throw ExceptionSupport::vmThrowable($ex);
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
            $ex = BuiltinExceptionSupport::materializeMysqliSqlException(
                $ctx,
                $e->getMessage(),
                '',
                0,
                $e->getCode()
            );
            throw ExceptionSupport::vmThrowable($ex);
        }
        if ($native->connect_errno) {
            $ex = BuiltinExceptionSupport::materializeMysqliSqlException(
                $ctx,
                $native->connect_error ?? 'Connection error',
                '',
                0,
                $native->connect_errno
            );
            throw ExceptionSupport::vmThrowable($ex);
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
        $native = VmMysqli::requireNative($receiver);
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
        $native = VmMysqli::requireNative($receiver);
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
        $this->receiver($frame, 'mysqli::prepare()');
        throw new \Error('mysqli::prepare() is not yet implemented in this compiler build (issue #3435)');
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
        $this->receiver($frame, 'mysqli::autocommit()');
        throw new \Error('mysqli::autocommit() is not yet implemented in this compiler build (issue #3435)');
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
        $this->receiver($frame, 'mysqli::begin_transaction()');
        throw new \Error('mysqli::begin_transaction() is not yet implemented in this compiler build (issue #3435)');
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
        $this->receiver($frame, 'mysqli::commit()');
        throw new \Error('mysqli::commit() is not yet implemented in this compiler build (issue #3435)');
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
        $this->receiver($frame, 'mysqli::rollback()');
        throw new \Error('mysqli::rollback() is not yet implemented in this compiler build (issue #3435)');
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
