<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared odbc_* semantics (php-src ext/odbc/php_odbc.c; #6293 / #21258).
 */
final class VmOdbcCore
{
    /**
     * @return Variable|false Odbc\Connection or false on failure
     */
    public static function connect(
        string $dsn,
        ?string $user,
        ?string $password,
        int $cursorOpt,
        Context $ctx,
        ?Frame $frame = null,
        string $function = 'odbc_connect'
    ): Variable|false {
        $uid = $user ?? '';
        $pwd = $password ?? '';
        $native = VmOdbcNative::connect($dsn, $uid, $pwd, $cursorOpt);
        if (null === $native) {
            VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
            self::warn(
                $ctx,
                \sprintf(
                    '%s(): SQL error: %s, SQL state %s in SQLConnect',
                    $function,
                    VmOdbcConnection::lastErrorMsg(),
                    VmOdbcConnection::lastState()
                ),
                $frame
            );

            return false;
        }
        VmOdbcConnection::setLastError('', '');

        return VmOdbcConnection::wrap($native, $ctx);
    }

    public static function close(ObjectEntry $connection): bool
    {
        return VmOdbcConnection::close($connection);
    }

    public static function closeAll(): void
    {
        VmOdbcConnection::closeAll();
    }

    /**
     * @return Variable|false
     */
    public static function exec(
        ObjectEntry $connection,
        string $query,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        if (!VmOdbcConnection::isLive($connection)) {
            throw new \TypeError('odbc_exec(): supplied resource is not a valid ODBC connection resource');
        }
        $native = VmOdbcConnection::native($connection);
        $hdbc = $native['hdbc'];
        if (null === $hdbc) {
            return self::execFail($ctx, $frame, 'SQLAllocStmt');
        }
        $hstmt = VmOdbcNative::allocStmt($hdbc);
        if (null === $hstmt) {
            return self::execFail($ctx, $frame, 'SQLAllocStmt');
        }
        if (!VmOdbcNative::execDirect($hstmt, $query)) {
            VmOdbcNative::freeStmt($hstmt);

            return self::execFail($ctx, $frame, 'SQLExecDirect');
        }
        $buffered = VmOdbcNative::bufferResult($hstmt);
        if (null === $buffered) {
            VmOdbcNative::freeStmt($hstmt);

            return self::execFail($ctx, $frame, 'SQLFetch');
        }
        VmOdbcConnection::setLastError('', '');

        return VmOdbcResult::wrap(
            $buffered['rows'],
            $connection,
            $ctx,
            $hstmt,
            $buffered['colnames'],
            $buffered['coltypes'],
            $buffered['collens']
        );
    }

    /**
     * @return Variable|false
     */
    public static function prepare(
        ObjectEntry $connection,
        string $query,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        if (!VmOdbcConnection::isLive($connection)) {
            throw new \TypeError('odbc_prepare(): supplied resource is not a valid ODBC connection resource');
        }
        $native = VmOdbcConnection::native($connection);
        $hdbc = $native['hdbc'];
        if (null === $hdbc) {
            return self::execFail($ctx, $frame, 'SQLAllocStmt', 'odbc_prepare');
        }
        $hstmt = VmOdbcNative::allocStmt($hdbc);
        if (null === $hstmt) {
            return self::execFail($ctx, $frame, 'SQLAllocStmt', 'odbc_prepare');
        }
        if (!VmOdbcNative::prepare($hstmt, $query)) {
            VmOdbcNative::freeStmt($hstmt);

            return self::execFail($ctx, $frame, 'SQLPrepare', 'odbc_prepare');
        }
        $numparams = VmOdbcNative::numParams($hstmt);
        $ncols = VmOdbcNative::numResultCols($hstmt);
        $colnames = [];
        $coltypes = [];
        $collens = [];
        for ($i = 1; $i <= $ncols; ++$i) {
            $meta = VmOdbcNative::describeCol($hstmt, $i);
            if (null === $meta) {
                $colnames[] = 'col'.$i;
                $coltypes[] = '';
                $collens[] = 0;
            } else {
                $colnames[] = $meta['name'];
                $coltypes[] = $meta['type'];
                $collens[] = $meta['len'];
            }
        }
        VmOdbcConnection::setLastError('', '');

        return VmOdbcResult::wrap(
            [],
            $connection,
            $ctx,
            $hstmt,
            $colnames,
            $coltypes,
            $collens,
            $numparams,
            false
        );
    }

    /**
     * @param list<mixed> $params PHP scalar values
     */
    public static function execute(
        ObjectEntry $result,
        array $params,
        Context $ctx,
        ?Frame $frame = null
    ): bool {
        VmOdbcResult::requireLive($result);
        $hstmt = VmOdbcResult::hstmt($result);
        if (null === $hstmt) {
            self::warn($ctx, 'odbc_execute(): SQL error: Failed to fetch error message, SQL state HY000 in SQLExecute', $frame);

            return false;
        }
        $need = VmOdbcResult::numParams($result);
        if ($need > 0 && \count($params) < $need) {
            self::warn(
                $ctx,
                \sprintf('odbc_execute(): Not enough parameters (%d should be %d) given', \count($params), $need),
                $frame
            );

            return false;
        }
        $binds = [];
        for ($i = 0; $i < $need; ++$i) {
            $val = $params[$i] ?? null;
            $str = null === $val ? null : (string) $val;
            $bag = VmOdbcNative::bindStringParam($hstmt, $i + 1, $str);
            if (null === $bag) {
                self::warn($ctx, 'odbc_execute(): SQL error: Failed to fetch error message, SQL state HY000 in SQLBindParameter', $frame);

                return false;
            }
            $binds[] = $bag;
        }
        if (!VmOdbcNative::execute($hstmt)) {
            self::warn($ctx, 'odbc_execute(): SQL error: Failed to fetch error message, SQL state HY000 in SQLExecute', $frame);

            return false;
        }
        $buffered = VmOdbcNative::bufferResult($hstmt);
        if (null === $buffered) {
            self::warn($ctx, 'odbc_execute(): SQL error: Failed to fetch error message, SQL state HY000 in SQLFetch', $frame);

            return false;
        }
        VmOdbcResult::applyBuffered(
            $result,
            $buffered['rows'],
            $buffered['colnames'],
            $buffered['coltypes'],
            $buffered['collens'],
            $binds
        );
        VmOdbcConnection::setLastError('', '');

        return true;
    }

    /**
     * @return Variable|false
     */
    public static function tables(
        ObjectEntry $connection,
        ?string $catalog,
        ?string $schema,
        ?string $table,
        ?string $types,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_tables',
            'SQLTables',
            static function ($hstmt) use ($catalog, $schema, $table, $types): bool {
                // Access-compat: empty schema + non-empty table → NULL schema
                if ('' === $schema && null !== $table && '' !== $table) {
                    $schema = null;
                }

                return VmOdbcNative::tables($hstmt, $catalog, $schema, $table, $types);
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function columns(
        ObjectEntry $connection,
        ?string $catalog,
        ?string $schema,
        ?string $table,
        ?string $column,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_columns',
            'SQLColumns',
            static function ($hstmt) use ($catalog, $schema, $table, $column): bool {
                if (null !== $table && '' !== $table && '' === $schema) {
                    $schema = null;
                }

                return VmOdbcNative::columns($hstmt, $catalog, $schema, $table, $column);
            }
        );
    }

    /**
     * odbc_autocommit — get status (int) or set mode (bool) (php-src; #21277).
     *
     * @return int|bool
     */
    public static function autocommit(
        ObjectEntry $connection,
        ?bool $enable,
        Context $ctx,
        ?Frame $frame = null
    ): int|bool {
        if (!VmOdbcConnection::isLive($connection)) {
            throw new \TypeError('odbc_autocommit(): supplied resource is not a valid ODBC connection resource');
        }
        $native = VmOdbcConnection::native($connection);
        $hdbc = $native['hdbc'];
        if (null === $hdbc) {
            self::warn($ctx, 'odbc_autocommit(): SQL error: Failed to fetch error message, SQL state HY000 in Set autocommit', $frame);

            return false;
        }
        if (null === $enable) {
            $status = VmOdbcNative::getAutocommit($hdbc);
            if (null === $status) {
                VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
                self::warn($ctx, 'odbc_autocommit(): SQL error: Failed to fetch error message, SQL state HY000 in Get commit status', $frame);

                return false;
            }
            VmOdbcConnection::setLastError('', '');

            return $status;
        }
        if (!VmOdbcNative::setAutocommit($hdbc, $enable)) {
            VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
            self::warn($ctx, 'odbc_autocommit(): SQL error: Failed to fetch error message, SQL state HY000 in Set autocommit', $frame);

            return false;
        }
        VmOdbcConnection::setLastError('', '');

        return true;
    }

    /**
     * odbc_commit / odbc_rollback via SQLTransact (php-src odbc_transact; #21277).
     */
    public static function transact(
        ObjectEntry $connection,
        bool $commit,
        Context $ctx,
        ?Frame $frame = null
    ): bool {
        $fn = $commit ? 'odbc_commit' : 'odbc_rollback';
        if (!VmOdbcConnection::isLive($connection)) {
            throw new \TypeError($fn.'(): supplied resource is not a valid ODBC connection resource');
        }
        $native = VmOdbcConnection::native($connection);
        $henv = $native['henv'];
        $hdbc = $native['hdbc'];
        if (null === $henv || null === $hdbc) {
            VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
            self::warn($ctx, $fn.'(): SQL error: Failed to fetch error message, SQL state HY000 in SQLTransact', $frame);

            return false;
        }
        if (!VmOdbcNative::transact($henv, $hdbc, $commit)) {
            VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
            self::warn($ctx, $fn.'(): SQL error: Failed to fetch error message, SQL state HY000 in SQLTransact', $frame);

            return false;
        }
        VmOdbcConnection::setLastError('', '');

        return true;
    }

    /**
     * odbc_fetch_array — BOTH keys (numeric + associative), like php_odbc_fetch with ODBC_BOTH.
     *
     * @return HashTable|false
     */
    public static function fetchArray(ObjectEntry $result, ?int $rowNumber): HashTable|false
    {
        if (!VmOdbcResult::fetchRow($result, $rowNumber)) {
            return false;
        }
        $values = VmOdbcResult::currentRowValues($result);
        if (false === $values) {
            return false;
        }
        $names = VmOdbcResult::colnames($result);
        $ht = new HashTable();
        foreach ($values as $i => $val) {
            $num = new Variable();
            self::setScalar($num, $val);
            $ht->add((string) $i, $num);
            $name = $names[$i] ?? (string) ($i + 1);
            $assoc = new Variable();
            self::setScalar($assoc, $val);
            $ht->add($name, $assoc);
        }

        return $ht;
    }

    /**
     * @return ObjectEntry|false
     */
    public static function fetchObject(ObjectEntry $result, Context $ctx, ?int $rowNumber): ObjectEntry|false
    {
        if (!VmOdbcResult::fetchRow($result, $rowNumber)) {
            return false;
        }
        $values = VmOdbcResult::currentRowValues($result);
        if (false === $values) {
            return false;
        }
        $names = VmOdbcResult::colnames($result);
        if (!isset($ctx->classes['stdclass'])) {
            $ce = new \PHPCompiler\VM\ClassEntry('stdClass');
            $ce->isInternal = true;
            $ctx->classes['stdclass'] = $ce;
        }
        $obj = new ObjectEntry($ctx->classes['stdclass']);
        $obj->constructed = true;
        foreach ($values as $i => $val) {
            $name = $names[$i] ?? (string) ($i + 1);
            $slot = $obj->allocateProperty($name);
            self::setScalar($slot, $val);
        }

        return $obj;
    }

    /**
     * @return HashTable|false numeric keys only
     */
    public static function fetchIntoRow(ObjectEntry $result, ?int $rowNumber): HashTable|false
    {
        if (!VmOdbcResult::fetchRow($result, $rowNumber)) {
            return false;
        }
        $values = VmOdbcResult::currentRowValues($result);
        if (false === $values) {
            return false;
        }
        $ht = new HashTable();
        foreach ($values as $i => $val) {
            $slot = new Variable();
            self::setScalar($slot, $val);
            $ht->add((string) $i, $slot);
        }

        return $ht;
    }

    /**
     * @param callable(\FFI\CData): bool $runner
     *
     * @return Variable|false
     */
    private static function catalogResult(
        ObjectEntry $connection,
        Context $ctx,
        ?Frame $frame,
        string $fn,
        string $sqlApi,
        callable $runner
    ): Variable|false {
        if (!VmOdbcConnection::isLive($connection)) {
            throw new \TypeError($fn.'(): supplied resource is not a valid ODBC connection resource');
        }
        $native = VmOdbcConnection::native($connection);
        $hdbc = $native['hdbc'];
        if (null === $hdbc) {
            return self::execFail($ctx, $frame, 'SQLAllocStmt', $fn);
        }
        $hstmt = VmOdbcNative::allocStmt($hdbc);
        if (null === $hstmt) {
            return self::execFail($ctx, $frame, 'SQLAllocStmt', $fn);
        }
        if (!$runner($hstmt)) {
            VmOdbcNative::freeStmt($hstmt);

            return self::execFail($ctx, $frame, $sqlApi, $fn);
        }
        $buffered = VmOdbcNative::bufferResult($hstmt);
        if (null === $buffered) {
            VmOdbcNative::freeStmt($hstmt);

            return self::execFail($ctx, $frame, 'SQLFetch', $fn);
        }
        VmOdbcConnection::setLastError('', '');

        return VmOdbcResult::wrap(
            $buffered['rows'],
            $connection,
            $ctx,
            $hstmt,
            $buffered['colnames'],
            $buffered['coltypes'],
            $buffered['collens']
        );
    }

    /**
     * @return false
     */
    private static function execFail(
        Context $ctx,
        ?Frame $frame,
        string $api,
        string $fn = 'odbc_exec'
    ): false {
        VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
        self::warn(
            $ctx,
            \sprintf(
                '%s(): SQL error: %s, SQL state %s in %s',
                $fn,
                VmOdbcConnection::lastErrorMsg(),
                VmOdbcConnection::lastState(),
                $api
            ),
            $frame
        );

        return false;
    }

    /**
     * @param mixed $val
     */
    private static function setScalar(Variable $slot, $val): void
    {
        if (null === $val) {
            $slot->null();
        } elseif (\is_int($val)) {
            $slot->int($val);
        } elseif (\is_float($val)) {
            $slot->float($val);
        } elseif (\is_bool($val)) {
            $slot->bool($val);
        } else {
            $slot->string((string) $val);
        }
    }

    private static function warn(Context $ctx, string $message, ?Frame $frame): void
    {
        $ctx->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }
}
