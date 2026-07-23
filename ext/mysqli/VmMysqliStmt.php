<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * mysqli_stmt VM class (php-src ext/mysqli/mysqli_api.c; #21788).
 *
 * Host bridge: wraps native \mysqli_stmt when ext/mysqli is loaded on harness PHP.
 */
final class VmMysqliStmt
{
    public const CLASS_LC = 'mysqli_stmt';

    /** @var array<int, MysqliStmtState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['execute'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('mysqli_stmt');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'bind_param' => new MysqliStmtBindParam(),
            'execute' => new MysqliStmtExecute(),
            'bind_result' => new MysqliStmtBindResult(),
            'fetch' => new MysqliStmtFetch(),
            'close' => new MysqliStmtClose(),
            'field_count' => new MysqliStmtFieldCount(),
            'param_count' => new MysqliStmtParamCount(),
            'sqlstate' => new MysqliStmtSqlstate(),
            'errno' => new MysqliStmtErrno(),
            'error' => new MysqliStmtError(),
            'insert_id' => new MysqliStmtInsertId(),
            'num_rows' => new MysqliStmtNumRows(),
            'affected_rows' => new MysqliStmtAffectedRows(),
            'data_seek' => new MysqliStmtDataSeek(),
            'reset' => new MysqliStmtReset(),
            'store_result' => new MysqliStmtStoreResult(),
            'free_result' => new MysqliStmtFreeResult(),
            'result_metadata' => new MysqliStmtResultMetadata(),
            'more_results' => new MysqliStmtMoreResults(),
            'next_result' => new MysqliStmtNextResult(),
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

    public static function wrap(Context $ctx, \mysqli_stmt $native): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli_stmt class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliStmtState();
        $state->native = $native;
        $state->ctx = $ctx;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    public static function state(ObjectEntry $entry): MysqliStmtState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('mysqli_stmt object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireNative(ObjectEntry $entry): \mysqli_stmt
    {
        $state = self::state($entry);
        if (null === $state->native) {
            throw new \LogicException('mysqli_stmt is closed');
        }

        return $state->native;
    }

    public static function destroyState(ObjectEntry $entry): void
    {
        unset(self::$store[$entry->id]);
    }

    public static function requireStmtObject(Variable $var, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf('%s(): Argument #1 ($statement) must be of type mysqli_stmt, %s given', $label, MysqliClassMethod::typeLabelPublic($resolved)));
        }
        $obj = $resolved->toObject();
        if (self::CLASS_LC !== strtolower($obj->class->name)) {
            throw new \TypeError(\sprintf('%s(): Argument #1 ($statement) must be of type mysqli_stmt, %s given', $label, $obj->class->name));
        }

        return $obj;
    }

    /**
     * @param list<Variable> $refVars
     */
    public static function bindParamNative(MysqliStmtState $state, string $types, array $refVars): bool
    {
        $native = $state->native ?? throw new \LogicException('mysqli_stmt is closed');
        $state->bindTypes = $types;
        $state->bindParamRefs = $refVars;
        $state->bindParamProxies = [];
        foreach ($refVars as $i => $ref) {
            $state->bindParamProxies[$i] = self::scalarFromVariable($ref->resolveIndirect());
        }
        $args = [$types];
        foreach ($state->bindParamProxies as &$proxy) {
            $args[] = &$proxy;
        }
        unset($proxy);

        return $native->bind_param(...$args);
    }

    public static function syncBindParamProxiesFromVm(MysqliStmtState $state): void
    {
        foreach ($state->bindParamRefs as $i => $ref) {
            $state->bindParamProxies[$i] = self::scalarFromVariable($ref->resolveIndirect());
        }
    }

    /**
     * @param list<Variable> $refVars
     */
    public static function bindResultNative(MysqliStmtState $state, array $refVars): bool
    {
        $native = $state->native ?? throw new \LogicException('mysqli_stmt is closed');
        $state->bindResultRefs = $refVars;
        $state->bindResultProxies = [];
        foreach ($refVars as $i => $ref) {
            $state->bindResultProxies[$i] = null;
        }
        $args = [];
        foreach ($state->bindResultProxies as &$proxy) {
            $args[] = &$proxy;
        }
        unset($proxy);

        return $native->bind_result(...$args);
    }

    public static function pushBindResultProxiesToVm(MysqliStmtState $state): void
    {
        foreach ($state->bindResultRefs as $i => $ref) {
            $proxy = $state->bindResultProxies[$i] ?? null;
            $target = $ref->byRefTarget();
            if (null === $proxy) {
                $target->null();
            } elseif (\is_int($proxy)) {
                $target->int($proxy);
            } elseif (\is_float($proxy)) {
                $target->float($proxy);
            } elseif (\is_bool($proxy)) {
                $target->bool($proxy);
            } else {
                $target->string((string) $proxy);
            }
        }
    }

    public static function scalarFromVariable(Variable $var): mixed
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => throw new \TypeError('mysqli_stmt bind values must be scalar'),
        };
    }

    public static function prepareOnLink(ObjectEntry $link, string $sql): ObjectEntry|false
    {
        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            return false;
        }
        $state = VmMysqli::state($link);
        $ctx = $state->ctx ?? throw new \LogicException('No VM context');
        $nativeLink = VmMysqli::requireNative($link, $ctx);
        $nativeStmt = $nativeLink->prepare($sql);
        if (false === $nativeStmt) {
            return false;
        }

        return self::wrap($ctx, $nativeStmt);
    }

    public static function fieldCount(ObjectEntry $stmt): int
    {
        return (int) self::requireNative($stmt)->field_count;
    }

    public static function paramCount(ObjectEntry $stmt): int
    {
        return (int) self::requireNative($stmt)->param_count;
    }

    public static function sqlstate(ObjectEntry $stmt): string
    {
        return (string) self::requireNative($stmt)->sqlstate;
    }

    public static function errno(ObjectEntry $stmt): int
    {
        return (int) self::requireNative($stmt)->errno;
    }

    public static function error(ObjectEntry $stmt): string
    {
        return (string) self::requireNative($stmt)->error;
    }

    /**
     * mysqli_stmt_error_list() — rows of {errno,sqlstate,error} (#22225).
     *
     * @return list<array{errno: int, sqlstate: string, error: string}>
     */
    public static function errorList(ObjectEntry $stmt): array
    {
        if (!isset(self::$store[$stmt->id])) {
            return [];
        }
        $native = self::$store[$stmt->id]->native;
        if (null === $native) {
            return [];
        }
        $list = $native->error_list;
        if (!\is_array($list)) {
            return [];
        }

        return VmMysqli::normalizeErrorList($list);
    }

    /** @return int|string */
    public static function insertId(ObjectEntry $stmt)
    {
        return self::requireNative($stmt)->insert_id;
    }

    public static function numRows(ObjectEntry $stmt): int
    {
        return (int) self::requireNative($stmt)->num_rows;
    }

    public static function affectedRows(ObjectEntry $stmt): int
    {
        return (int) self::requireNative($stmt)->affected_rows;
    }

    public static function dataSeek(ObjectEntry $stmt, int $offset): bool
    {
        return self::requireNative($stmt)->data_seek($offset);
    }

    public static function reset(ObjectEntry $stmt): bool
    {
        return self::requireNative($stmt)->reset();
    }

    public static function storeResult(ObjectEntry $stmt): bool
    {
        return self::requireNative($stmt)->store_result();
    }

    public static function freeResult(ObjectEntry $stmt): bool
    {
        self::requireNative($stmt)->free_result();

        return true;
    }

    public static function resultMetadata(ObjectEntry $stmt): ObjectEntry|false
    {
        $native = self::requireNative($stmt);
        $meta = $native->result_metadata();
        if (false === $meta || null === $meta) {
            return false;
        }
        $ctx = self::state($stmt)->ctx ?? throw new \LogicException('mysqli_stmt requires VM context');

        return VmMysqliResult::wrap($ctx, $meta);
    }

    public static function moreResults(ObjectEntry $stmt): bool
    {
        return self::requireNative($stmt)->more_results();
    }

    public static function nextResult(ObjectEntry $stmt): bool
    {
        return self::requireNative($stmt)->next_result();
    }
}

/** @internal */
final class MysqliStmtState
{
    public ?\mysqli_stmt $native = null;

    public ?Context $ctx = null;

    public string $bindTypes = '';

    /** @var list<Variable> */
    public array $bindParamRefs = [];

    /** @var list<mixed> */
    public array $bindParamProxies = [];

    /** @var list<Variable> */
    public array $bindResultRefs = [];

    /** @var list<mixed> */
    public array $bindResultProxies = [];
}

final class MysqliStmtBindParam extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('bind_param');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::bind_param()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('mysqli_stmt::bind_param() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given');
        }
        $types = $this->stringArg($frame->calledArgs[1], 'mysqli_stmt::bind_param', 0, 'types');
        $refVars = [];
        for ($i = 2, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $refVars[] = $frame->calledArgs[$i];
        }
        $ok = VmMysqliStmt::bindParamNative(VmMysqliStmt::state($receiver), $types, $refVars);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class MysqliStmtExecute extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('execute');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::execute()');
        $state = VmMysqliStmt::state($receiver);
        VmMysqliStmt::syncBindParamProxiesFromVm($state);
        $native = VmMysqliStmt::requireNative($receiver);
        $ok = $native->execute();
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class MysqliStmtBindResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('bind_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::bind_result()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_stmt::bind_result() expects at least 1 argument, 0 given');
        }
        $refVars = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $refVars[] = $frame->calledArgs[$i];
        }
        $ok = VmMysqliStmt::bindResultNative(VmMysqliStmt::state($receiver), $refVars);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class MysqliStmtFetch extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::fetch()');
        $state = VmMysqliStmt::state($receiver);
        $native = VmMysqliStmt::requireNative($receiver);
        $ok = $native->fetch();
        if ($ok) {
            VmMysqliStmt::pushBindResultProxiesToVm($state);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class MysqliStmtClose extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::close()');
        $state = VmMysqliStmt::state($receiver);
        if (null !== $state->native) {
            $state->native->close();
            $state->native = null;
        }
        VmMysqliStmt::destroyState($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class MysqliStmtFieldCount extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('field_count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::field_count()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::fieldCount($receiver));
        }
    }
}

final class MysqliStmtParamCount extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('param_count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::param_count()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::paramCount($receiver));
        }
    }
}

final class MysqliStmtSqlstate extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('sqlstate');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::sqlstate()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqliStmt::sqlstate($receiver));
        }
    }
}

final class MysqliStmtErrno extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('errno');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::errno()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::errno($receiver));
        }
    }
}

final class MysqliStmtError extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('error');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::error()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqliStmt::error($receiver));
        }
    }
}

final class MysqliStmtInsertId extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('insert_id');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::insert_id()');
        if (null !== $frame->returnVar) {
            VmMysqli::assignInsertId($frame->returnVar, VmMysqliStmt::insertId($receiver));
        }
    }
}

final class MysqliStmtNumRows extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('num_rows');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::num_rows()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::numRows($receiver));
        }
    }
}

final class MysqliStmtAffectedRows extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('affected_rows');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::affected_rows()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::affectedRows($receiver));
        }
    }
}

final class MysqliStmtDataSeek extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('data_seek');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::data_seek()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_stmt::data_seek() expects exactly 1 argument, 0 given');
        }
        $offset = $this->intArg($frame->calledArgs[1], 'mysqli_stmt::data_seek', 0, 'offset');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::dataSeek($receiver, $offset));
        }
    }
}

final class MysqliStmtReset extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('reset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::reset()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::reset($receiver));
        }
    }
}

final class MysqliStmtStoreResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('store_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::store_result()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::storeResult($receiver));
        }
    }
}

final class MysqliStmtFreeResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('free_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::free_result()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::freeResult($receiver));
        }
    }
}

final class MysqliStmtResultMetadata extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('result_metadata');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::result_metadata()');
        if (null === $frame->returnVar) {
            return;
        }
        $meta = VmMysqliStmt::resultMetadata($receiver);
        if (false === $meta) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($meta);
        }
    }
}

final class MysqliStmtMoreResults extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('more_results');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::more_results()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::moreResults($receiver));
        }
    }
}

final class MysqliStmtNextResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('next_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_stmt::next_result()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::nextResult($receiver));
        }
    }
}
