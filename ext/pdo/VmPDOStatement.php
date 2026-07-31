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
use PHPCompiler\ext\spl\InternalIteratorBuiltin;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmExecNative;
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
        foreach (['Traversable', 'Iterator', 'IteratorAggregate'] as $iface) {
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
            'fetchcolumn' => new PDOStatementFetchColumn(),
            'fetchobject' => new PDOStatementFetchObject(),
            'bindvalue' => new PDOStatementBindValue(),
            'bindparam' => new PDOStatementBindParam(),
            'bindcolumn' => new PDOStatementBindColumn(),
            'rowcount' => new PDOStatementRowCount(),
            'columncount' => new PDOStatementColumnCount(),
            'closecursor' => new PDOStatementCloseCursor(),
            'setfetchmode' => new PDOStatementSetFetchMode(),
            'errorcode' => new PDOStatementErrorCode(),
            'errorinfo' => new PDOStatementErrorInfo(),
            'getcolumnmeta' => new PDOStatementGetColumnMeta(),
            'getattribute' => new PDOStatementGetAttribute(),
            'setattribute' => new PDOStatementSetAttribute(),
            'nextrowset' => new PDOStatementNextRowset(),
            'debugdumpparams' => new PDOStatementDebugDumpParams(),
            'getiterator' => new PDOStatementGetIterator(),
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
        $entry->methodNames['fetchcolumn'] = 'fetchColumn';
        $entry->methodNames['fetchobject'] = 'fetchObject';
        $entry->methodNames['bindvalue'] = 'bindValue';
        $entry->methodNames['bindparam'] = 'bindParam';
        $entry->methodNames['bindcolumn'] = 'bindColumn';
        $entry->methodNames['rowcount'] = 'rowCount';
        $entry->methodNames['columncount'] = 'columnCount';
        $entry->methodNames['closecursor'] = 'closeCursor';
        $entry->methodNames['setfetchmode'] = 'setFetchMode';
        $entry->methodNames['errorcode'] = 'errorCode';
        $entry->methodNames['errorinfo'] = 'errorInfo';
        $entry->methodNames['getcolumnmeta'] = 'getColumnMeta';
        $entry->methodNames['getattribute'] = 'getAttribute';
        $entry->methodNames['setattribute'] = 'setAttribute';
        $entry->methodNames['nextrowset'] = 'nextRowset';
        $entry->methodNames['debugdumpparams'] = 'debugDumpParams';
        $entry->methodNames['getiterator'] = 'getIterator';

        self::$classEntry = $entry;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param \FFI\CData $stmt sqlite3_stmt*
     */
    public static function create(ObjectEntry $pdo, $stmt, string $sql, bool $executed, int $rowCount = 0): ObjectEntry
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
        $state->rowCount = $rowCount;
        $state->fetchMode = VmPDO::state($pdo)->fetchMode;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    /**
     * php-src pdo_sqlite execute: SQLITE_DONE sets row_count from sqlite3_changes();
     * SQLITE_ROW (SELECT with data) leaves row_count at 0.
     *
     * @param \FFI\CData $stmt sqlite3_stmt*
     * @param \FFI\CData $db sqlite3*
     */
    public static function rowCountAfterStep($stmt, $db, int $stepRc): int
    {
        if (VmSqlite3Native::STEP_DONE === $stepRc) {
            return VmSqlite3Native::changes($db);
        }

        return 0;
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
        $how = self::fetchHow($mode);
        // php-src do_fetch PDO_FETCH_KEY_PAIR — exactly 2 columns (#25640).
        if (PdoConstants::FETCH_KEY_PAIR === $how && 2 !== $count) {
            VmPDO::raiseImplError(
                VmPDO::stateById($st->pdoId),
                'HY000',
                'PDO::FETCH_KEY_PAIR fetch mode requires the result set to contain exactly 2 columns.'
            );

            return false;
        }
        $assoc = [];
        $num = [];
        for ($i = 0; $i < $count; ++$i) {
            $name = VmSqlite3Native::columnName($st->stmt, $i);
            $value = VmSqlite3Native::columnValueAt($st->stmt, $i);
            $assoc[$name] = $value;
            $num[$i] = $value;
        }
        // FETCH_OBJ / FETCH_CLASS / FETCH_INTO use assoc keys (php-src property update).
        // FETCH_COLUMN / FETCH_KEY_PAIR / FETCH_FUNC use numeric indices (#25578, #25640, #25641).
        $row = match ($how) {
            PdoConstants::FETCH_ASSOC,
            PdoConstants::FETCH_OBJ,
            PdoConstants::FETCH_CLASS,
            PdoConstants::FETCH_INTO => $assoc,
            PdoConstants::FETCH_NUM,
            PdoConstants::FETCH_COLUMN,
            PdoConstants::FETCH_KEY_PAIR,
            PdoConstants::FETCH_FUNC => $num,
            PdoConstants::FETCH_BOUND => $assoc + $num,
            default => $assoc + $num,
        };
        $st->current = $row;
        ++$st->key;
        if (PdoConstants::FETCH_BOUND === $mode || [] !== $st->boundColumns) {
            self::applyBoundColumns($st, $assoc, $num);
        }

        return $row;
    }

    /** Low byte of PDO fetch mode (php-src `how & ~PDO_FETCH_FLAGS`). */
    public static function fetchHow(int $mode): int
    {
        return $mode & 0xff;
    }

    /** High fetch flags (PROPS_LATE / CLASSTYPE / GROUP / …). */
    public static function fetchFlags(int $mode): int
    {
        return $mode & ~0xff;
    }

    /**
     * Materialize one fetched row into $returnVar for the given fetch mode (php-src do_fetch).
     *
     * @param array<string|int, mixed> $row
     *
     * @return bool false when an impl error left no result (caller returns false)
     */
    public static function assignFetchResult(
        Context $ctx,
        Variable $returnVar,
        PdoStatementState $st,
        int $mode,
        array $row,
        ?int $columnOverride = null
    ): bool {
        $how = self::fetchHow($mode);
        $flags = self::fetchFlags($mode);
        if (PdoConstants::FETCH_OBJ === $how) {
            self::assignStdClassFromAssoc($ctx, $returnVar, $row);

            return true;
        }
        if (PdoConstants::FETCH_COLUMN === $how) {
            $col = $columnOverride ?? $st->fetchColumn;
            if ($col < 0) {
                throw new \ValueError('Column index must be greater than or equal to 0');
            }
            if (!\array_key_exists($col, $row)) {
                throw new \ValueError('Invalid column index');
            }
            VmPDO::assignScalar($returnVar, $row[$col]);

            return true;
        }
        if (PdoConstants::FETCH_KEY_PAIR === $how) {
            // Single-row KEY_PAIR: one-element map {col0 => col1} (php-src do_fetch).
            $ht = new HashTable();
            self::addKeyPairEntry($ht, $row);
            $returnVar->array($ht);

            return true;
        }
        if (PdoConstants::FETCH_CLASS === $how) {
            return self::assignFetchClass($ctx, $returnVar, $st, $row, $flags, null, null);
        }
        if (PdoConstants::FETCH_INTO === $how) {
            return self::assignFetchInto($returnVar, $st, $row);
        }
        if (PdoConstants::FETCH_FUNC === $how) {
            return self::assignFetchFunc($ctx, $returnVar, $st, $row, null);
        }
        VmPDO::assignRow($returnVar, $row);

        return true;
    }

    /**
     * PDO_FETCH_CLASS — object_init_ex + property update + optional ctor (php-src do_fetch, #25641).
     *
     * @param array<string|int, mixed> $assoc
     * @param list<Variable>|null      $ctorOverride temporary fetchAll/fetchObject ctor args
     */
    public static function assignFetchClass(
        Context $ctx,
        Variable $returnVar,
        PdoStatementState $st,
        array $assoc,
        int $flags,
        ?string $classOverride = null,
        ?array $ctorOverride = null
    ): bool {
        $className = $classOverride ?? $st->fetchClassName;
        if (null === $className || '' === $className) {
            // php-src: No fetch class specified (ATTR_DEFAULT_FETCH_MODE / bare fetch).
            VmPDO::raiseImplError(
                VmPDO::stateById($st->pdoId),
                'HY000',
                'No fetch class specified'
            );

            return false;
        }
        $lc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            throw new \Error('Class "'.$className.'" not found');
        }
        $ce = $ctx->classes[$lc];
        if ($ce->isEnum || $ce->isInterface || $ce->isTrait || $ce->isAbstract) {
            throw new \Error('Cannot instantiate '.($ce->isEnum ? 'enum' : ($ce->isInterface ? 'interface' : ($ce->isTrait ? 'trait' : 'abstract class'))).' '.$ce->name);
        }
        $object = new ObjectEntry($ce);
        $ctorArgs = $ctorOverride ?? $st->fetchCtorArgs;
        $propsLate = 0 !== ($flags & PdoConstants::FETCH_PROPS_LATE);
        $vm = $ctx->runtime->vm ?? null;
        if ($propsLate && null !== $ce->constructor) {
            if (null === $vm) {
                throw new \LogicException('PDO FETCH_CLASS requires active VM for constructor');
            }
            $vm->invokeInstanceMethod($object, '__construct', ...$ctorArgs);
            self::assignPropertiesFromAssoc($object, $assoc);
        } else {
            self::assignPropertiesFromAssoc($object, $assoc);
            if (null !== $ce->constructor) {
                if (null === $vm) {
                    throw new \LogicException('PDO FETCH_CLASS requires active VM for constructor');
                }
                $vm->invokeInstanceMethod($object, '__construct', ...$ctorArgs);
            }
        }
        $object->constructed = true;
        $returnVar->object($object);

        return true;
    }

    /**
     * PDO_FETCH_INTO — update properties on the stored object (php-src do_fetch, #25641).
     *
     * @param array<string|int, mixed> $assoc
     */
    public static function assignFetchInto(Variable $returnVar, PdoStatementState $st, array $assoc): bool
    {
        if (null === $st->fetchInto) {
            VmPDO::raiseImplError(
                VmPDO::stateById($st->pdoId),
                'HY000',
                'No fetch-into object specified.'
            );

            return false;
        }
        self::assignPropertiesFromAssoc($st->fetchInto, $assoc);
        $returnVar->object($st->fetchInto);

        return true;
    }

    /**
     * PDO_FETCH_FUNC — call callback with column values (php-src do_fetch / fetchAll, #25641).
     *
     * @param array<int, mixed> $numRow
     */
    public static function assignFetchFunc(
        Context $ctx,
        Variable $returnVar,
        PdoStatementState $st,
        array $numRow,
        ?Variable $funcOverride = null
    ): bool {
        $callback = $funcOverride ?? $st->fetchFunc;
        if (null === $callback) {
            VmPDO::raiseImplError(
                VmPDO::stateById($st->pdoId),
                'HY000',
                'No fetch function specified'
            );

            return false;
        }
        $args = [];
        foreach ($numRow as $value) {
            $slot = new Variable();
            VmPDO::assignScalar($slot, $value);
            $args[] = $slot;
        }
        $result = VmCallable::invokeAs('PDOStatement::fetchAll', $ctx, $callback, ...$args);
        $returnVar->copyFrom($result);

        return true;
    }

    /**
     * Write column values onto object properties (php-src zend_update_property_ex).
     *
     * @param array<string|int, mixed> $assoc
     */
    public static function assignPropertiesFromAssoc(ObjectEntry $object, array $assoc): void
    {
        foreach ($assoc as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $slot = $object->hasProperty($key)
                ? $object->getProperty($key)->resolveIndirect()
                : $object->allocateProperty($key);
            VmPDO::assignScalar($slot, $value);
        }
    }

    /**
     * @return list<Variable>
     */
    public static function ctorArgsFromVariable(Variable $var, string $label, int $argIndex): array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return [];
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            $type = match ($resolved->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_BOOLEAN => 'bool',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_OBJECT => $resolved->toObject()->class->name,
                default => 'mixed',
            };
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d must be of type ?array, %s given',
                $label,
                $argIndex,
                $type
            ));
        }
        $out = [];
        foreach ($resolved->toArray()->iterate() as $slot) {
            $copy = new Variable();
            $copy->copyFrom($slot->resolveIndirect());
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * Insert one KEY_PAIR row into an accumulating map (php-src do_fetch PDO_FETCH_KEY_PAIR).
     *
     * @param array<int|string, mixed> $numRow numeric columns; expects indices 0 and 1
     */
    public static function addKeyPairEntry(HashTable $ht, array $numRow): void
    {
        $key = $numRow[0] ?? null;
        $value = $numRow[1] ?? null;
        $slot = new Variable();
        VmPDO::assignScalar($slot, $value);
        $ht->add(\is_int($key) ? (string) $key : (string) $key, $slot);
    }

    /**
     * Build stdClass from associative column map (php-src PDO_FETCH_OBJ / fetchObject).
     *
     * @param array<string|int, mixed> $assoc
     */
    public static function assignStdClassFromAssoc(Context $ctx, Variable $returnVar, array $assoc): void
    {
        if (!isset($ctx->classes['stdclass'])) {
            throw new \LogicException('stdClass is not registered');
        }
        $object = new ObjectEntry($ctx->classes['stdclass']);
        $object->constructed = true;
        foreach ($assoc as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $slot = $object->allocateProperty($key);
            VmPDO::assignScalar($slot, $value);
        }
        $returnVar->object($object);
    }

    /**
     * Write fetched column values into bindColumn Variable slots (php-src FETCH_BOUND).
     *
     * @param array<string, mixed> $assoc
     * @param array<int, mixed>    $num
     */
    public static function applyBoundColumns(PdoStatementState $st, array $assoc, array $num): void
    {
        foreach ($st->boundColumns as $key => $var) {
            $value = null;
            $found = false;
            if (\is_int($key)) {
                // bindColumn 1-based column numbers.
                $idx = $key - 1;
                if (\array_key_exists($idx, $num)) {
                    $value = $num[$idx];
                    $found = true;
                }
            } else {
                if (\array_key_exists($key, $assoc)) {
                    $value = $assoc[$key];
                    $found = true;
                } else {
                    foreach ($assoc as $name => $cell) {
                        if (0 === \strcasecmp((string) $name, $key)) {
                            $value = $cell;
                            $found = true;
                            break;
                        }
                    }
                }
            }
            if ($found) {
                VmPDO::assignScalar($var->resolveIndirect(), $value);
            }
        }
    }

    /**
     * Resolve bindColumn column: 1-based int or column name string.
     *
     * @return int|string|null int 1-based index or string name
     */
    public static function resolveColumn(PdoStatementState $st, Variable $columnVar, string $label): int|string|null
    {
        $resolved = $columnVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $n = $resolved->toInt();

            return $n >= 1 ? $n : null;
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            $n = (int) $resolved->toFloat();

            return $n >= 1 ? $n : null;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $name = $resolved->toString();

            return '' !== $name ? $name : null;
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? 1 : null;
        }

        throw new \TypeError(
            \sprintf('%s(): Argument #1 ($column) must be of type string|int', $label)
        );
    }

    /** Dump bound params to php://output (php-src zim_PDOStatement_debugDumpParams; #22274). */
    public static function debugDumpParamsText(PdoStatementState $st): string
    {
        $sql = $st->sql;
        $out = 'SQL: ['.\strlen($sql).'] '.$sql."\n";
        $out .= 'Params: '.\count($st->bound)."\n";
        foreach ($st->bound as $paramno => $entry) {
            $out .= 'Key: Position #'.$paramno.":\n";
            $out .= 'paramno='.$paramno."\n";
            $out .= "name=[0] \"\"\n";
            $out .= 'is_param='.('param' === $entry['kind'] ? '1' : '0')."\n";
            $out .= 'param_type='.PdoConstants::PARAM_STR."\n";
        }

        return $out;
    }

    public static function clearError(PdoStatementState $st): void
    {
        $st->errorCode = '00000';
        $st->errorDriverCode = null;
        $st->errorMessage = null;
    }

    public static function setError(PdoStatementState $st, string $sqlState, ?int $driverCode, ?string $message): void
    {
        $st->errorCode = $sqlState;
        $st->errorDriverCode = $driverCode;
        $st->errorMessage = $message;
    }

    /**
     * Resolve 1-based bind index from int or named placeholder.
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

    /**
     * Apply stored binds (1-based) to the native statement.
     *
     * @param \FFI\CData $stmt
     */
    public static function applyBindings(PdoStatementState $st, $stmt): void
    {
        foreach ($st->bound as $index => $entry) {
            if ('param' === $entry['kind']) {
                $value = VmPDO::phpValueFromVariable($entry['var']);
            } else {
                $value = $entry['value'];
            }
            VmSqlite3Native::bindValue($stmt, (int) $index, $value);
        }
    }

    /**
     * Map sqlite affinity / runtime type to PDO getColumnMeta shape (pdo_sqlite_stmt_col_meta).
     *
     * @return array<string, mixed>|false
     */
    public static function columnMeta(PdoStatementState $st, int $column): array|false
    {
        if (null === $st->stmt || $column < 0) {
            return false;
        }
        $count = VmSqlite3Native::columnCount($st->stmt);
        if ($column >= $count) {
            return false;
        }
        $name = VmSqlite3Native::columnName($st->stmt, $column);
        $decl = VmSqlite3Native::columnDecltype($st->stmt, $column);
        $native = self::nativeTypeFromDecl($decl);
        $pdoType = match ($native) {
            'integer' => PdoConstants::PARAM_INT,
            'null' => PdoConstants::PARAM_NULL,
            default => PdoConstants::PARAM_STR,
        };

        return [
            'native_type' => $native,
            'sqlite:decl_type' => $decl,
            'flags' => [],
            'name' => $name,
            'len' => -1,
            'precision' => 0,
            'pdo_type' => $pdoType,
        ];
    }

    private static function nativeTypeFromDecl(string $decl): string
    {
        $upper = strtoupper($decl);
        if ('' === $upper) {
            return 'null';
        }
        if (str_contains($upper, 'INT')) {
            return 'integer';
        }
        if (str_contains($upper, 'CHAR') || str_contains($upper, 'CLOB') || str_contains($upper, 'TEXT')) {
            return 'string';
        }
        if (str_contains($upper, 'BLOB') || '' === $decl) {
            return 'blob';
        }
        if (str_contains($upper, 'REAL') || str_contains($upper, 'FLOA') || str_contains($upper, 'DOUB')) {
            return 'double';
        }

        return 'string';
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

    /**
     * Column index for PDO::FETCH_COLUMN (php-src stmt->fetch.column; default 0).
     */
    public int $fetchColumn = 0;

    /**
     * Class name for PDO::FETCH_CLASS (php-src stmt->fetch.cls.ce; #25641).
     */
    public ?string $fetchClassName = null;

    /**
     * Constructor args for PDO::FETCH_CLASS (php-src stmt->fetch.cls.ctor_args).
     *
     * @var list<Variable>
     */
    public array $fetchCtorArgs = [];

    /** Object for PDO::FETCH_INTO (php-src stmt->fetch.into). */
    public ?ObjectEntry $fetchInto = null;

    /** Callable for PDO::FETCH_FUNC during fetchAll (php-src stmt->fetch.func). */
    public ?Variable $fetchFunc = null;

    /** Rows affected by last DML execute (php-src stmt->row_count / sqlite3_changes). */
    public int $rowCount = 0;

    public int $key = -1;

    /** @var array<string|int, mixed>|null */
    public ?array $current = null;

    /**
     * 1-based param index => bind entry (php-src bound_params; #19853 bindParam).
     *
     * @var array<int, array{kind: 'value', value: mixed}|array{kind: 'param', var: Variable}>
     */
    public array $bound = [];

    /**
     * Column key (1-based int or name) => Variable slot (php-src bound_columns; #22274).
     *
     * @var array<int|string, Variable>
     */
    public array $boundColumns = [];

    public string $errorCode = '00000';

    public ?int $errorDriverCode = null;

    public ?string $errorMessage = null;
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
            if (\count($frame->calledArgs) >= 2) {
                $arg = $frame->calledArgs[1]->resolveIndirect();
                if (Variable::TYPE_ARRAY === $arg->type) {
                    $i = 1;
                    foreach ($arg->toArray()->iterate() as $slot) {
                        VmSqlite3Native::bindValue($st->stmt, $i, VmPDO::phpValueFromVariable($slot));
                        ++$i;
                    }
                }
            }
            VmPDOStatement::applyBindings($st, $st->stmt);
            $rc = VmSqlite3Native::step($st->stmt);
            if (VmSqlite3Native::STEP_ROW !== $rc && VmSqlite3Native::STEP_DONE !== $rc) {
                $pdoState = VmPDO::stateById($st->pdoId);
                $msg = 'SQL execution failed';
                VmPDOStatement::setError($st, 'HY000', null, $msg);
                VmPDO::raise($pdoState, $msg);
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $db = $pdoState->db;
            if (null === $db) {
                throw new \LogicException('PDO object has not been correctly initialized by its constructor');
            }
            $st->rowCount = VmPDOStatement::rowCountAfterStep($st->stmt, $db, $rc);
            // Leave cursor at start for subsequent fetch/foreach (SELECT).
            VmSqlite3Native::reset($st->stmt);
            $st->executed = true;
            $st->exhausted = false;
            $st->key = -1;
            $st->current = null;
            VmPDOStatement::clearError($st);
            VmPDO::clearError($pdoState);
        } catch (\SQLite3Exception $e) {
            VmPDOStatement::setError($st, 'HY000', (int) $e->getCode(), $e->getMessage());
            VmPDO::raise($pdoState, $e->getMessage(), 'HY000', (int) $e->getCode());
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
        $how = VmPDOStatement::fetchHow($mode);
        // php-src pdo_verify_fetch_mode: FETCH_FUNC is fetchAll-only.
        if (PdoConstants::FETCH_FUNC === $how) {
            throw new \ValueError(
                'PDOStatement::fetch(): Argument #1 ($mode) PDO::FETCH_FUNC can only be used with PDOStatement::fetchAll()'
            );
        }
        $row = VmPDOStatement::fetchRow($st, $mode);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        if (PdoConstants::FETCH_BOUND === $how) {
            $frame->returnVar->bool(true);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDOStatement::fetch() requires VM context');
        }
        if (PdoConstants::FETCH_LAZY === $how) {
            // FETCH_LAZY uses assoc column names on PDORow (php-src pdo_get_lazy_object; #22294).
            $assoc = [];
            foreach ($row as $key => $value) {
                if (\is_string($key)) {
                    $assoc[$key] = $value;
                }
            }
            $frame->returnVar->object(VmPDORow::fromRow($ctx, $st, $assoc));

            return;
        }
        // FETCH_OBJ / FETCH_COLUMN / FETCH_CLASS / INTO / array modes (#25578, #25641).
        if (!VmPDOStatement::assignFetchResult($ctx, $frame->returnVar, $st, $mode, $row)) {
            $frame->returnVar->bool(false);
        }
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
        $how = VmPDOStatement::fetchHow($mode);
        // php-src do_fetch_opt_type: PDO::FETCH_LAZY cannot be used with fetchAll().
        if (PdoConstants::FETCH_LAZY === $how) {
            throw new \ValueError(
                'PDOStatement::fetchAll(): Argument #1 ($mode) PDO::FETCH_LAZY cannot be used with PDOStatement::fetchAll()'
            );
        }
        // Optional column / class / callback for fetchAll (php-src zim_PDOStatement_fetchAll).
        $columnOverride = null;
        $classOverride = null;
        $ctorOverride = null;
        $funcOverride = null;
        $savedColumn = $st->fetchColumn;
        $savedClass = $st->fetchClassName;
        $savedCtor = $st->fetchCtorArgs;
        $savedFunc = $st->fetchFunc;
        $argc = \count($frame->calledArgs) - 1; // exclude $this
        if (PdoConstants::FETCH_COLUMN === $how) {
            if ($argc > 2) {
                throw new \ArgumentCountError(
                    'PDOStatement::fetchAll() expects at most 2 arguments for the fetch mode provided, '
                    .$argc.' given'
                );
            }
            if ($argc >= 2) {
                $columnOverride = $this->intArg($frame->calledArgs[2], 'PDOStatement::fetchAll', 1, 'args');
                if ($columnOverride < 0) {
                    throw new \ValueError(
                        'PDOStatement::fetchAll(): Argument #2 ($args) must be greater than or equal to 0'
                    );
                }
                $st->fetchColumn = $columnOverride;
            }
        } elseif (PdoConstants::FETCH_CLASS === $how) {
            if ($argc > 3) {
                throw new \ArgumentCountError(
                    'PDOStatement::fetchAll() expects at most 3 arguments for the fetch mode provided, '
                    .$argc.' given'
                );
            }
            // php-src: missing class → stdClass.
            $classOverride = 'stdClass';
            if ($argc >= 2) {
                $arg2 = $frame->calledArgs[2]->resolveIndirect();
                if (Variable::TYPE_NULL !== $arg2->type) {
                    $classOverride = $this->stringArg($frame->calledArgs[2], 'PDOStatement::fetchAll', 1, 'class');
                }
            }
            if ($argc >= 3) {
                $ctorOverride = VmPDOStatement::ctorArgsFromVariable(
                    $frame->calledArgs[3],
                    'PDOStatement::fetchAll',
                    3
                );
            }
            $st->fetchClassName = $classOverride;
            $st->fetchCtorArgs = $ctorOverride ?? [];
        } elseif (PdoConstants::FETCH_FUNC === $how) {
            if (2 !== $argc) {
                throw new \ArgumentCountError(
                    'PDOStatement::fetchAll() expects exactly 2 argument for PDO::FETCH_FUNC, '
                    .$argc.' given'
                );
            }
            $funcOverride = $frame->calledArgs[2]->resolveIndirect();
            $st->fetchFunc = $funcOverride;
        } elseif ($argc > 1) {
            throw new \ArgumentCountError(
                'PDOStatement::fetchAll() expects exactly 1 argument for the fetch mode provided, '
                .$argc.' given'
            );
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDOStatement::fetchAll() requires VM context');
        }
        $ht = new HashTable();
        $i = 0;
        try {
            // FETCH_KEY_PAIR accumulates into one map, not a list of rows (php-src #25640).
            if (PdoConstants::FETCH_KEY_PAIR === $how) {
                while (false !== ($row = VmPDOStatement::fetchRow($st, $mode))) {
                    VmPDOStatement::addKeyPairEntry($ht, $row);
                }
            } else {
                while (false !== ($row = VmPDOStatement::fetchRow($st, $mode))) {
                    $slot = new Variable();
                    if (PdoConstants::FETCH_CLASS === $how) {
                        if (!VmPDOStatement::assignFetchClass(
                            $ctx,
                            $slot,
                            $st,
                            $row,
                            VmPDOStatement::fetchFlags($mode),
                            $classOverride,
                            $ctorOverride
                        )) {
                            break;
                        }
                    } elseif (PdoConstants::FETCH_FUNC === $how) {
                        if (!VmPDOStatement::assignFetchFunc($ctx, $slot, $st, $row, $funcOverride)) {
                            break;
                        }
                    } elseif (!VmPDOStatement::assignFetchResult($ctx, $slot, $st, $mode, $row, $columnOverride)) {
                        break;
                    }
                    $ht->add((string) $i, $slot);
                    ++$i;
                }
            }
        } finally {
            $st->fetchColumn = $savedColumn;
            $st->fetchClassName = $savedClass;
            $st->fetchCtorArgs = $savedCtor;
            $st->fetchFunc = $savedFunc;
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
        if (null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $param = VmPDOStatement::resolveParamIndex($st->stmt, $frame->calledArgs[1], 'PDOStatement::bindValue');
        if (null === $param) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $value = VmPDO::phpValueFromVariable($frame->calledArgs[2]);
        $st->bound[$param] = ['kind' => 'value', 'value' => $value];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/** PDOStatement::bindParam — keep Variable slot for live execute (#19853). */
final class PDOStatementBindParam extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('bindParam');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::bindParam()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'PDOStatement::bindParam() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $st = VmPDOStatement::state($receiver);
        if (null === $st->stmt) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $param = VmPDOStatement::resolveParamIndex($st->stmt, $frame->calledArgs[1], 'PDOStatement::bindParam');
        if (null === $param) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $st->bound[$param] = ['kind' => 'param', 'var' => $frame->calledArgs[2]];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/** php-src zim_PDOStatement_fetchColumn — next row, return column $column (default 0). */
final class PDOStatementFetchColumn extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('fetchColumn');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::fetchColumn()');
        $st = VmPDOStatement::state($receiver);
        $column = 0;
        if (\count($frame->calledArgs) >= 2) {
            $column = $this->intArg($frame->calledArgs[1], 'PDOStatement::fetchColumn', 0, 'column');
        }
        $row = VmPDOStatement::fetchRow($st, PdoConstants::FETCH_NUM);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $row || !\array_key_exists($column, $row)) {
            $frame->returnVar->bool(false);

            return;
        }
        VmPDO::assignScalar($frame->returnVar, $row[$column]);
    }
}

/**
 * php-src zim_PDOStatement_fetchObject — FETCH_CLASS into class (stdClass default; #25641).
 */
final class PDOStatementFetchObject extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('fetchObject');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::fetchObject()');
        $st = VmPDOStatement::state($receiver);
        $className = 'stdClass';
        $ctorArgs = [];
        if (\count($frame->calledArgs) >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $className = $this->stringArg($frame->calledArgs[1], 'PDOStatement::fetchObject', 0, 'class');
            }
        }
        if (\count($frame->calledArgs) >= 3) {
            $ctorArgs = VmPDOStatement::ctorArgsFromVariable(
                $frame->calledArgs[2],
                'PDOStatement::fetchObject',
                2
            );
        }
        $row = VmPDOStatement::fetchRow($st, PdoConstants::FETCH_ASSOC);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDOStatement::fetchObject() requires VM context');
        }
        if (!VmPDOStatement::assignFetchClass(
            $ctx,
            $frame->returnVar,
            $st,
            $row,
            0,
            $className,
            $ctorArgs
        )) {
            $frame->returnVar->bool(false);
        }
    }
}

/** php-src zim_PDOStatement_rowCount — stmt->row_count (sqlite3_changes on DML DONE). */
final class PDOStatementRowCount extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('rowCount');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::rowCount()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmPDOStatement::state($receiver)->rowCount);
        }
    }
}

/** php-src zim_PDOStatement_columnCount — number of result columns. */
final class PDOStatementColumnCount extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('columnCount');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::columnCount()');
        $st = VmPDOStatement::state($receiver);
        $count = 0;
        if (null !== $st->stmt) {
            $count = VmSqlite3Native::columnCount($st->stmt);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}

/**
 * php-src zim_PDOStatement_closeCursor + pdo_sqlite_stmt_cursor_closer —
 * sqlite3_reset; stmt->executed = 0.
 */
final class PDOStatementCloseCursor extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('closeCursor');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::closeCursor()');
        $st = VmPDOStatement::state($receiver);
        if (null !== $st->stmt) {
            VmSqlite3Native::reset($st->stmt);
        }
        $st->executed = false;
        $st->exhausted = false;
        $st->key = -1;
        $st->current = null;
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

/** PDOStatement::setFetchMode(int $mode, mixed ...$args): bool — php-src zim_PDOStatement_setFetchMode (#19853). */
final class PDOStatementSetFetchMode extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('setFetchMode');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::setFetchMode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDOStatement::setFetchMode() expects at least 1 argument, 0 given');
        }
        $st = VmPDOStatement::state($receiver);
        $mode = $this->intArg($frame->calledArgs[1], 'PDOStatement::setFetchMode', 0, 'mode');
        $variadic = \count($frame->calledArgs) - 2;
        // Low byte is the fetch type; high bits are PDO_FETCH_* flags (GROUP/UNIQUE/…).
        $how = $mode & 0xff;
        // Clear previous CLASS/INTO/FUNC state (php-src pdo_stmt_free_default_fetch_mode).
        $st->fetchClassName = null;
        $st->fetchCtorArgs = [];
        $st->fetchInto = null;
        $st->fetchFunc = null;
        if (PdoConstants::FETCH_COLUMN === $how) {
            // php-src: FETCH_COLUMN requires exactly the column index arg.
            if (1 !== $variadic) {
                throw new \ArgumentCountError(
                    'PDOStatement::setFetchMode() expects exactly 2 arguments for the fetch mode provided, '
                    .(\count($frame->calledArgs) - 1).' given'
                );
            }
            $col = $this->intArg($frame->calledArgs[2], 'PDOStatement::setFetchMode', 1, 'args');
            if ($col < 0) {
                throw new \ValueError(
                    'PDOStatement::setFetchMode(): Argument #2 ($args) must be greater than or equal to 0'
                );
            }
            $st->fetchColumn = $col;
        } elseif (PdoConstants::FETCH_CLASS === $how) {
            // php-src: FETCH_CLASSTYPE takes no class name; otherwise class + optional ctor args.
            if (0 !== (VmPDOStatement::fetchFlags($mode) & PdoConstants::FETCH_CLASSTYPE)) {
                if (0 !== $variadic) {
                    throw new \ArgumentCountError(
                        'PDOStatement::setFetchMode() expects exactly 1 argument for the fetch mode provided, '
                        .(\count($frame->calledArgs) - 1).' given'
                    );
                }
            } else {
                if (0 === $variadic) {
                    throw new \ArgumentCountError(
                        'PDOStatement::setFetchMode() expects at least 2 arguments for the fetch mode provided, '
                        .(\count($frame->calledArgs) - 1).' given'
                    );
                }
                if ($variadic > 2) {
                    throw new \ArgumentCountError(
                        'PDOStatement::setFetchMode() expects at most 3 arguments for the fetch mode provided, '
                        .(\count($frame->calledArgs) - 1).' given'
                    );
                }
                $className = $this->stringArg($frame->calledArgs[2], 'PDOStatement::setFetchMode', 1, 'class');
                $ctx = $frame->vmContext;
                if (null === $ctx) {
                    throw new \LogicException('PDOStatement::setFetchMode() requires VM context');
                }
                $lc = strtolower(ltrim($className, '\\'));
                if (!isset($ctx->classes[$lc])) {
                    $ctx->autoloadClass($className);
                }
                if (!isset($ctx->classes[$lc])) {
                    throw new \TypeError(
                        'PDOStatement::setFetchMode(): Argument #2 ($class) must be a valid class'
                    );
                }
                $st->fetchClassName = $className;
                if (2 === $variadic) {
                    $st->fetchCtorArgs = VmPDOStatement::ctorArgsFromVariable(
                        $frame->calledArgs[3],
                        'PDOStatement::setFetchMode',
                        3
                    );
                    $ce = $ctx->classes[$lc];
                    if ([] !== $st->fetchCtorArgs && null === $ce->constructor) {
                        throw new \ValueError(
                            'PDOStatement::setFetchMode(): Argument #3 must be empty when class provided in argument #2 ($class) does not have a constructor'
                        );
                    }
                }
            }
        } elseif (PdoConstants::FETCH_INTO === $how) {
            if (1 !== $variadic) {
                throw new \ArgumentCountError(
                    'PDOStatement::setFetchMode() expects exactly 2 arguments for the fetch mode provided, '
                    .(\count($frame->calledArgs) - 1).' given'
                );
            }
            $objVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $objVar->type) {
                throw new \TypeError(\sprintf(
                    'PDOStatement::setFetchMode(): Argument #2 ($object) must be of type object, %s given',
                    self::typeLabel($frame->calledArgs[2])
                ));
            }
            $st->fetchInto = $objVar->toObject();
        } elseif (0 !== $variadic) {
            throw new \ArgumentCountError(
                'PDOStatement::setFetchMode() expects exactly 1 argument for the fetch mode provided, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $st->fetchMode = $mode;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/** PDOStatement::errorCode(): ?string — php-src zim_PDOStatement_errorCode (#19853). */
final class PDOStatementErrorCode extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('errorCode');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::errorCode()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmPDOStatement::state($receiver)->errorCode);
        }
    }
}

/** PDOStatement::errorInfo(): array — php-src zim_PDOStatement_errorInfo (#19853). */
final class PDOStatementErrorInfo extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('errorInfo');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::errorInfo()');
        if (null === $frame->returnVar) {
            return;
        }
        $st = VmPDOStatement::state($receiver);
        $ht = new HashTable();
        $c0 = new Variable();
        $c0->string($st->errorCode);
        $ht->add('0', $c0);
        $c1 = new Variable();
        if (null === $st->errorDriverCode) {
            $c1->null();
        } else {
            $c1->int($st->errorDriverCode);
        }
        $ht->add('1', $c1);
        $c2 = new Variable();
        if (null === $st->errorMessage) {
            $c2->null();
        } else {
            $c2->string($st->errorMessage);
        }
        $ht->add('2', $c2);
        $frame->returnVar->array($ht);
    }
}

/** PDOStatement::getColumnMeta(int $column): array|false — php-src zim_PDOStatement_getColumnMeta (#19853). */
final class PDOStatementGetColumnMeta extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('getColumnMeta');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::getColumnMeta()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDOStatement::getColumnMeta() expects exactly 1 argument, 0 given');
        }
        $column = $this->intArg($frame->calledArgs[1], 'PDOStatement::getColumnMeta', 0, 'column');
        $st = VmPDOStatement::state($receiver);
        $meta = VmPDOStatement::columnMeta($st, $column);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $meta) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($meta as $key => $value) {
            $slot = new Variable();
            if (\is_array($value)) {
                $slot->array(new HashTable());
            } else {
                VmPDO::assignScalar($slot, $value);
            }
            $ht->add((string) $key, $slot);
        }
        $frame->returnVar->array($ht);
    }
}

/** PDOStatement::bindColumn — php-src zim_PDOStatement_bindColumn (#22274). */
final class PDOStatementBindColumn extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('bindColumn');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::bindColumn()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'PDOStatement::bindColumn() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $st = VmPDOStatement::state($receiver);
        $column = VmPDOStatement::resolveColumn($st, $frame->calledArgs[1], 'PDOStatement::bindColumn');
        if (null === $column) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $st->boundColumns[$column] = $frame->calledArgs[2];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

/**
 * PDOStatement::getAttribute — php-src zim_PDOStatement_getAttribute (#22274).
 * Sqlite: ATTR_EMULATE_PREPARES=false; other attrs → IM001.
 */
final class PDOStatementGetAttribute extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttribute');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::getAttribute()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PDOStatement::getAttribute() expects exactly 1 argument, 0 given');
        }
        $attr = $this->intArg($frame->calledArgs[1], 'PDOStatement::getAttribute', 0, 'attribute');
        $st = VmPDOStatement::state($receiver);
        $pdoState = VmPDO::stateById($st->pdoId);
        if (PdoConstants::ATTR_EMULATE_PREPARES === $attr) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        VmPDO::raiseImplError($pdoState, 'IM001', "driver doesn't support getting that attribute");
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false);
        }
    }
}

/**
 * PDOStatement::setAttribute — php-src zim_PDOStatement_setAttribute (#22274).
 * Sqlite generic attrs unsupported → false (explain-mode attrs deferred).
 */
final class PDOStatementSetAttribute extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttribute');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, 'PDOStatement::setAttribute()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'PDOStatement::setAttribute() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false);
        }
    }
}

/**
 * PDOStatement::nextRowset — sqlite has no next_rowset (#22274).
 * php-src: IM001 "driver does not support multiple rowsets".
 */
final class PDOStatementNextRowset extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('nextRowset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::nextRowset()');
        $st = VmPDOStatement::state($receiver);
        $pdoState = VmPDO::stateById($st->pdoId);
        VmPDO::raiseImplError($pdoState, 'IM001', 'driver does not support multiple rowsets');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false);
        }
    }
}

/** PDOStatement::debugDumpParams — php://output dump (#22274). */
final class PDOStatementDebugDumpParams extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('debugDumpParams');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::debugDumpParams()');
        $st = VmPDOStatement::state($receiver);
        VmExecNative::echoToStdout(VmPDOStatement::debugDumpParamsText($st));
    }
}

/**
 * PDOStatement::getIterator — InternalIterator over rows (#22274).
 * php-src: zend_create_internal_iterator_zval.
 */
final class PDOStatementGetIterator extends PdoClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'PDOStatement::getIterator()');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('PDOStatement::getIterator() requires VM context');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(
            InternalIteratorBuiltin::fromLiveHandler($ctx, new PdoStatementInternalIterator($receiver))
        );
    }
}

/**
 * Live InternalIterator backing for PDOStatement (#22274).
 */
final class PdoStatementInternalIterator implements \PHPCompiler\ext\spl\InternalIteratorLiveHandler
{
    public function __construct(private ObjectEntry $statement)
    {
    }

    public function rewind(): void
    {
        $st = VmPDOStatement::state($this->statement);
        if (null !== $st->stmt && $st->executed) {
            VmSqlite3Native::reset($st->stmt);
            $st->exhausted = false;
            $st->key = -1;
            $st->current = null;
            VmPDOStatement::fetchRow($st, PdoConstants::FETCH_ASSOC);
        }
    }

    public function next(): void
    {
        VmPDOStatement::fetchRow(VmPDOStatement::state($this->statement), PdoConstants::FETCH_ASSOC);
    }

    public function valid(): bool
    {
        $st = VmPDOStatement::state($this->statement);

        return null !== $st->current && !$st->exhausted;
    }

    public function current(): Variable
    {
        $var = new Variable();
        $st = VmPDOStatement::state($this->statement);
        if (null === $st->current) {
            $var->null();

            return $var;
        }
        VmPDO::assignRow($var, $st->current);

        return $var;
    }

    public function key(): int|string|Variable
    {
        return VmPDOStatement::state($this->statement)->key;
    }
}
