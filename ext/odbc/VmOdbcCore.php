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
     * @return Variable|false
     */
    public static function primaryKeys(
        ObjectEntry $connection,
        ?string $catalog,
        string $schema,
        string $table,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_primarykeys',
            'SQLPrimaryKeys',
            static function ($hstmt) use ($catalog, $schema, $table): bool {
                return VmOdbcNative::primaryKeys($hstmt, $catalog, $schema, $table);
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function foreignKeys(
        ObjectEntry $connection,
        ?string $pkCatalog,
        string $pkSchema,
        string $pkTable,
        string $fkCatalog,
        string $fkSchema,
        string $fkTable,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_foreignkeys',
            'SQLForeignKeys',
            static function ($hstmt) use ($pkCatalog, $pkSchema, $pkTable, $fkCatalog, $fkSchema, $fkTable): bool {
                return VmOdbcNative::foreignKeys(
                    $hstmt,
                    $pkCatalog,
                    $pkSchema,
                    $pkTable,
                    $fkCatalog,
                    $fkSchema,
                    $fkTable
                );
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function statistics(
        ObjectEntry $connection,
        ?string $catalog,
        string $schema,
        string $table,
        int $unique,
        int $accuracy,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_statistics',
            'SQLStatistics',
            static function ($hstmt) use ($catalog, $schema, $table, $unique, $accuracy): bool {
                return VmOdbcNative::statistics($hstmt, $catalog, $schema, $table, $unique, $accuracy);
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function getTypeInfo(
        ObjectEntry $connection,
        int $dataType,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_gettypeinfo',
            'SQLGetTypeInfo',
            static function ($hstmt) use ($dataType): bool {
                return VmOdbcNative::getTypeInfo($hstmt, $dataType);
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function specialColumns(
        ObjectEntry $connection,
        int $type,
        ?string $catalog,
        string $schema,
        string $table,
        int $scope,
        int $nullable,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_specialcolumns',
            'SQLSpecialColumns',
            static function ($hstmt) use ($type, $catalog, $schema, $table, $scope, $nullable): bool {
                return VmOdbcNative::specialColumns(
                    $hstmt,
                    $type,
                    $catalog,
                    $schema,
                    $table,
                    $scope,
                    $nullable
                );
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function procedures(
        ObjectEntry $connection,
        ?string $catalog,
        ?string $schema,
        ?string $procedure,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_procedures',
            'SQLProcedures',
            static function ($hstmt) use ($catalog, $schema, $procedure): bool {
                return VmOdbcNative::procedures($hstmt, $catalog, $schema, $procedure);
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function procedureColumns(
        ObjectEntry $connection,
        ?string $catalog,
        ?string $schema,
        ?string $procedure,
        ?string $column,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_procedurecolumns',
            'SQLProcedureColumns',
            static function ($hstmt) use ($catalog, $schema, $procedure, $column): bool {
                return VmOdbcNative::procedureColumns($hstmt, $catalog, $schema, $procedure, $column);
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function tablePrivileges(
        ObjectEntry $connection,
        ?string $catalog,
        string $schema,
        string $table,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_tableprivileges',
            'SQLTablePrivileges',
            static function ($hstmt) use ($catalog, $schema, $table): bool {
                return VmOdbcNative::tablePrivileges($hstmt, $catalog, $schema, $table);
            }
        );
    }

    /**
     * @return Variable|false
     */
    public static function columnPrivileges(
        ObjectEntry $connection,
        ?string $catalog,
        string $schema,
        string $table,
        string $column,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        return self::catalogResult(
            $connection,
            $ctx,
            $frame,
            'odbc_columnprivileges',
            'SQLColumnPrivileges',
            static function ($hstmt) use ($catalog, $schema, $table, $column): bool {
                return VmOdbcNative::columnPrivileges($hstmt, $catalog, $schema, $table, $column);
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
     * odbc_next_result — SQLMoreResults + re-buffer (php-src php_odbc.c).
     */
    public static function nextResult(
        ObjectEntry $result,
        Context $ctx,
        ?Frame $frame = null
    ): bool {
        VmOdbcResult::requireLive($result);
        $hstmt = VmOdbcResult::hstmt($result);
        if (null === $hstmt) {
            self::warn($ctx, 'odbc_next_result(): SQL error: Failed to fetch error message, SQL state HY000 in SQLMoreResults', $frame);

            return false;
        }
        $more = VmOdbcNative::moreResults($hstmt);
        if (true === $more) {
            VmOdbcNative::unbindStmt($hstmt);
            $numparams = VmOdbcNative::numParams($hstmt);
            VmOdbcResult::setNumParams($result, $numparams);
            $buffered = VmOdbcNative::bufferResult($hstmt);
            if (null === $buffered) {
                self::warn($ctx, 'odbc_next_result(): SQL error: Failed to fetch error message, SQL state HY000 in SQLFetch', $frame);

                return false;
            }
            VmOdbcResult::applyBuffered(
                $result,
                $buffered['rows'],
                $buffered['colnames'],
                $buffered['coltypes'],
                $buffered['collens']
            );
            VmOdbcConnection::setLastError('', '');

            return true;
        }
        if (false === $more) {
            return false;
        }
        self::warn($ctx, 'odbc_next_result(): SQL error: Failed to fetch error message, SQL state HY000 in SQLMoreResults', $frame);

        return false;
    }

    /**
     * odbc_data_source — SQLDataSources (php-src php_odbc.c).
     *
     * @return HashTable|null|false
     */
    public static function dataSource(
        ObjectEntry $connection,
        int $fetchType,
        Context $ctx,
        ?Frame $frame = null
    ): HashTable|null|false {
        if (!VmOdbcConnection::isLive($connection)) {
            throw new \TypeError('odbc_data_source(): supplied resource is not a valid ODBC connection resource');
        }
        if (OdbcConstants::SQL_FETCH_FIRST !== $fetchType && OdbcConstants::SQL_FETCH_NEXT !== $fetchType) {
            throw new \ValueError('odbc_data_source(): Argument #2 ($fetch_type) must be either SQL_FETCH_FIRST or SQL_FETCH_NEXT');
        }
        $native = VmOdbcConnection::native($connection);
        $henv = $native['henv'];
        if (null === $henv) {
            self::warn($ctx, 'odbc_data_source(): SQL error: Failed to fetch error message, SQL state HY000 in SQLDataSources', $frame);

            return false;
        }
        $row = VmOdbcNative::dataSources($henv, $fetchType);
        if (null === $row) {
            return null;
        }
        if (false === $row) {
            self::warn($ctx, 'odbc_data_source(): SQL error: Failed to fetch error message, SQL state HY000 in SQLDataSources', $frame);
            VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');

            return false;
        }
        VmOdbcConnection::setLastError('', '');
        $ht = new HashTable();
        $server = new Variable();
        $server->string($row['server']);
        $ht->add('server', $server);
        $desc = new Variable();
        $desc->string($row['description']);
        $ht->add('description', $desc);

        return $ht;
    }

    public static function binmode(ObjectEntry $result, int $mode): bool
    {
        VmOdbcResult::setBinmode($result, $mode);

        return true;
    }

    public static function longreadlen(ObjectEntry $result, int $length): bool
    {
        VmOdbcResult::setLongreadlen($result, $length);

        return true;
    }

    /**
     * odbc_setoption — which=1 SQLSetConnectOption, which=2 SQLSetStmtOption (php-src; #21267).
     *
     * @param ObjectEntry $handle Odbc\Connection or Odbc\Result
     */
    public static function setoption(
        ObjectEntry $handle,
        int $which,
        int $option,
        int $value,
        Context $ctx,
        ?Frame $frame = null
    ): bool {
        if (1 === $which) {
            if (!VmOdbcConnection::isLive($handle)) {
                throw new \TypeError('odbc_setoption(): supplied resource is not a valid ODBC connection resource');
            }
            $native = VmOdbcConnection::native($handle);
            $hdbc = $native['hdbc'];
            if (null === $hdbc) {
                VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
                self::warn($ctx, 'odbc_setoption(): SQL error: Failed to fetch error message, SQL state HY000 in SetConnectOption', $frame);

                return false;
            }
            if (!VmOdbcNative::setConnectOption($hdbc, $option, $value)) {
                VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
                self::warn($ctx, 'odbc_setoption(): SQL error: Failed to fetch error message, SQL state HY000 in SetConnectOption', $frame);

                return false;
            }
            VmOdbcConnection::setLastError('', '');

            return true;
        }
        if (2 === $which) {
            if (!VmOdbcResult::isLive($handle)) {
                throw new \TypeError('odbc_setoption(): supplied resource is not a valid ODBC result resource');
            }
            $hstmt = VmOdbcResult::hstmt($handle);
            if (null === $hstmt) {
                VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
                self::warn($ctx, 'odbc_setoption(): SQL error: Failed to fetch error message, SQL state HY000 in SetStmtOption', $frame);

                return false;
            }
            if (!VmOdbcNative::setStmtOption($hstmt, $option, $value)) {
                VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
                self::warn($ctx, 'odbc_setoption(): SQL error: Failed to fetch error message, SQL state HY000 in SetStmtOption', $frame);

                return false;
            }
            VmOdbcConnection::setLastError('', '');

            return true;
        }
        throw new \ValueError('odbc_setoption(): Argument #2 ($which) must be 1 for SQLSetConnectOption(), or 2 for SQLSetStmtOption()');
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
