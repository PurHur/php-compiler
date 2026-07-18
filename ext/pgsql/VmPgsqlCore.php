<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared pg_* semantics (php-src ext/pgsql/pgsql.c; #3741).
 */
final class VmPgsqlCore
{
    /**
     * @return Variable|false PgSql\Connection object variable, or false on failure
     */
    public static function connect(string $conninfo, Context $ctx): Variable|false
    {
        if (!VmPgsqlNative::available()) {
            VmPgsqlConnection::setLastError('could not find driver');

            return false;
        }
        $native = VmPgsqlNative::connect($conninfo);
        if (null === $native) {
            VmPgsqlConnection::setLastError('PQconnectdb failed');

            return false;
        }
        if (VmPgsqlNative::CONNECTION_OK !== VmPgsqlNative::status($native)) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($native));
            VmPgsqlNative::finish($native);

            return false;
        }
        VmPgsqlConnection::setLastError('');

        return VmPgsqlConnection::wrap($native, $ctx);
    }

    /**
     * @return Variable|false PgSql\Result object variable, or false on failure
     */
    public static function query(ObjectEntry $connection, string $query, Context $ctx): Variable|false
    {
        $conn = VmPgsqlConnection::native($connection);
        $result = VmPgsqlNative::exec($conn, $query);
        if (null === $result) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));

            return false;
        }
        $status = VmPgsqlNative::resultStatus($result);
        if (VmPgsqlNative::PGRES_TUPLES_OK !== $status
            && VmPgsqlNative::PGRES_COMMAND_OK !== $status
            && VmPgsqlNative::PGRES_EMPTY_QUERY !== $status
        ) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
            VmPgsqlNative::clear($result);

            return false;
        }
        VmPgsqlConnection::setLastError('');

        return VmPgsqlResult::wrap($result, $ctx, $connection);
    }

    /**
     * pg_copy_to — COPY table TO STDOUT (php-src ext/pgsql/pgsql.c; #20629).
     *
     * @return HashTable|false packed list of row strings
     */
    public static function copyTo(ObjectEntry $connection, string $tableName, string $separator, string $nullAs): HashTable|false
    {
        if (1 !== \strlen($separator)) {
            throw new \ValueError('pg_copy_to(): Argument #3 ($separator) must be one character');
        }
        $conn = VmPgsqlConnection::native($connection);
        $query = \sprintf(
            "COPY %s TO STDOUT DELIMITER E'%s' NULL AS E'%s'",
            $tableName,
            self::escapeCopyEString($separator),
            self::escapeCopyEString($nullAs)
        );
        VmPgsqlNative::drainResults($conn);
        $pgsqlResult = VmPgsqlNative::exec($conn, $query);
        $status = null !== $pgsqlResult
            ? VmPgsqlNative::resultStatus($pgsqlResult)
            : VmPgsqlNative::status($conn);
        if (VmPgsqlNative::PGRES_COPY_OUT !== $status) {
            if (null !== $pgsqlResult) {
                VmPgsqlNative::clear($pgsqlResult);
            }
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
            @\trigger_error('pg_copy_to(): Copy command failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

            return false;
        }
        if (null !== $pgsqlResult) {
            VmPgsqlNative::clear($pgsqlResult);
        }
        $ht = new HashTable();
        $idx = 0;
        while (true) {
            [$ret, $row] = VmPgsqlNative::getCopyData($conn);
            if (-1 === $ret) {
                break;
            }
            if (0 === $ret || -2 === $ret) {
                VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
                @\trigger_error('pg_copy_to(): getline failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

                return false;
            }
            $slot = new Variable();
            $slot->string($row);
            $ht->add((string) $idx, $slot);
            ++$idx;
        }
        VmPgsqlNative::drainResults($conn);
        VmPgsqlConnection::setLastError('');

        return $ht;
    }

    /**
     * pg_copy_from — COPY table FROM STDIN (php-src ext/pgsql/pgsql.c; #20629).
     *
     * @param list<string>|HashTable $rows
     */
    public static function copyFrom(ObjectEntry $connection, string $tableName, HashTable $rows, string $separator, string $nullAs): bool
    {
        if (1 !== \strlen($separator)) {
            throw new \ValueError('pg_copy_from(): Argument #4 ($separator) must be one character');
        }
        $conn = VmPgsqlConnection::native($connection);
        $query = \sprintf(
            "COPY %s FROM STDIN DELIMITER E'%s' NULL AS E'%s'",
            $tableName,
            self::escapeCopyEString($separator),
            self::escapeCopyEString($nullAs)
        );
        VmPgsqlNative::drainResults($conn);
        $pgsqlResult = VmPgsqlNative::exec($conn, $query);
        $status = null !== $pgsqlResult
            ? VmPgsqlNative::resultStatus($pgsqlResult)
            : VmPgsqlNative::status($conn);
        if (VmPgsqlNative::PGRES_COPY_IN !== $status) {
            if (null !== $pgsqlResult) {
                VmPgsqlNative::clear($pgsqlResult);
            }
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
            @\trigger_error('pg_copy_from(): Copy command failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

            return false;
        }
        if (null !== $pgsqlResult) {
            VmPgsqlNative::clear($pgsqlResult);
        }
        foreach ($rows->iterateKeyed(true) as [, $valueVar]) {
            $line = $valueVar->resolveIndirect()->toString();
            if ('' !== $line && "\n" !== $line[\strlen($line) - 1]) {
                $line .= "\n";
            }
            if (1 !== VmPgsqlNative::putCopyData($conn, $line)) {
                VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
                @\trigger_error('pg_copy_from(): copy failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

                return false;
            }
        }
        if (1 !== VmPgsqlNative::putCopyEnd($conn, null)) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
            @\trigger_error('pg_copy_from(): putcopyend failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

            return false;
        }
        $commandFailed = false;
        while (null !== ($res = VmPgsqlNative::getResult($conn))) {
            if (VmPgsqlNative::PGRES_COMMAND_OK !== VmPgsqlNative::resultStatus($res)) {
                VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
                @\trigger_error('pg_copy_from(): Copy command failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);
                $commandFailed = true;
            }
            VmPgsqlNative::clear($res);
        }
        if ($commandFailed) {
            return false;
        }
        VmPgsqlConnection::setLastError('');

        return true;
    }

    /**
     * pg_meta_data — catalog columns for a table (php-src php_pgsql_meta_data; #20629).
     *
     * @return HashTable|false fieldname => meta assoc
     */
    public static function metaData(ObjectEntry $connection, string $tableName, bool $extended): HashTable|false
    {
        if ('' === $tableName) {
            throw new \ValueError('pg_meta_data(): Argument #2 ($table_name) must not be empty');
        }
        $conn = VmPgsqlConnection::native($connection);
        $parts = \explode('.', $tableName, 2);
        if (1 === \count($parts) || '' === $parts[1]) {
            $schema = 'public';
            $rel = $parts[0];
        } else {
            $schema = $parts[0];
            $rel = $parts[1];
        }
        if ('' === $rel) {
            throw new \ValueError(\sprintf('pg_meta_data(): Argument #2 ($table_name) must be specified (%s)', $tableName));
        }
        $escRel = VmPgsqlNative::escapeStringConn($conn, $rel);
        $escSchema = VmPgsqlNative::escapeStringConn($conn, $schema);
        if (false === $escRel || false === $escSchema) {
            @\trigger_error(\sprintf("pg_meta_data(): Escaping table name '%s' failed", $tableName), \E_USER_WARNING);

            return false;
        }
        if ($extended) {
            $query = 'SELECT a.attname, a.attnum, t.typname, a.attlen, a.attnotnull, a.atthasdef, a.attndims, t.typtype, '
                .'d.description '
                .'FROM pg_class as c '
                .' JOIN pg_attribute a ON (a.attrelid = c.oid) '
                .' JOIN pg_type t ON (a.atttypid = t.oid) '
                .' JOIN pg_namespace n ON (c.relnamespace = n.oid) '
                .' LEFT JOIN pg_description d ON (d.objoid=a.attrelid AND d.objsubid=a.attnum AND c.oid=d.objoid) '
                ."WHERE a.attnum > 0  AND c.relname = '{$escRel}' AND n.nspname = '{$escSchema}' ORDER BY a.attnum;";
        } else {
            $query = 'SELECT a.attname, a.attnum, t.typname, a.attlen, a.attnotnull, a.atthasdef, a.attndims, t.typtype '
                .'FROM pg_class as c '
                .' JOIN pg_attribute a ON (a.attrelid = c.oid) '
                .' JOIN pg_type t ON (a.atttypid = t.oid) '
                .' JOIN pg_namespace n ON (c.relnamespace = n.oid) '
                ."WHERE a.attnum > 0 AND c.relname = '{$escRel}' AND n.nspname = '{$escSchema}' ORDER BY a.attnum;";
        }
        $pgResult = VmPgsqlNative::exec($conn, $query);
        if (null === $pgResult
            || VmPgsqlNative::PGRES_TUPLES_OK !== VmPgsqlNative::resultStatus($pgResult)
            || 0 === VmPgsqlNative::ntuples($pgResult)
        ) {
            @\trigger_error(\sprintf("pg_meta_data(): Table '%s' doesn't exists", $tableName), \E_USER_WARNING);
            if (null !== $pgResult) {
                VmPgsqlNative::clear($pgResult);
            }

            return false;
        }
        $meta = new HashTable();
        $n = VmPgsqlNative::ntuples($pgResult);
        for ($i = 0; $i < $n; ++$i) {
            $elem = new HashTable();
            self::htAddLong($elem, 'num', (int) VmPgsqlNative::getvalue($pgResult, $i, 1));
            self::htAddString($elem, 'type', VmPgsqlNative::getvalue($pgResult, $i, 2));
            self::htAddLong($elem, 'len', (int) VmPgsqlNative::getvalue($pgResult, $i, 3));
            self::htAddBool($elem, 'not null', 't' === VmPgsqlNative::getvalue($pgResult, $i, 4));
            self::htAddBool($elem, 'has default', 't' === VmPgsqlNative::getvalue($pgResult, $i, 5));
            self::htAddLong($elem, 'array dims', (int) VmPgsqlNative::getvalue($pgResult, $i, 6));
            $typtype = VmPgsqlNative::getvalue($pgResult, $i, 7);
            self::htAddBool($elem, 'is enum', 'e' === $typtype);
            if ($extended) {
                self::htAddBool($elem, 'is base', 'b' === $typtype);
                self::htAddBool($elem, 'is composite', 'c' === $typtype);
                self::htAddBool($elem, 'is pseudo', 'p' === $typtype);
                self::htAddString($elem, 'description', VmPgsqlNative::getvalue($pgResult, $i, 8));
            }
            $name = VmPgsqlNative::getvalue($pgResult, $i, 0);
            $slot = new Variable();
            $slot->array($elem);
            $meta->add($name, $slot);
        }
        VmPgsqlNative::clear($pgResult);

        return $meta;
    }

    /**
     * pg_convert — convert assoc values to SQL literals (php-src php_pgsql_convert; #20629).
     *
     * Practical subset: null/bool/int/float/string via escapeLiteral; full regex type checks deferred.
     *
     * @return HashTable|false
     */
    public static function convert(ObjectEntry $connection, string $tableName, HashTable $values, int $flags = 0): HashTable|false
    {
        if ('' === $tableName) {
            throw new \ValueError('pg_convert(): Argument #2 ($table_name) must not be empty');
        }
        $meta = self::metaData($connection, $tableName, false);
        if (false === $meta) {
            return false;
        }
        $conn = VmPgsqlConnection::native($connection);
        $out = new HashTable();
        foreach ($values->iterateKeyed(true) as [$keyVar, $valVar]) {
            $field = $keyVar->resolveIndirect()->toString();
            if ('' === $field || null === $meta->find($field)) {
                @\trigger_error(\sprintf('pg_convert(): Invalid field name (%s) in values', $field), \E_USER_NOTICE);

                return false;
            }
            $val = $valVar->resolveIndirect();
            if (Variable::TYPE_ARRAY === $val->type || Variable::TYPE_OBJECT === $val->type || Variable::TYPE_RESOURCE === $val->type) {
                throw new \TypeError(\sprintf(
                    'pg_convert(): Values must be of type string|int|float|bool|null, %s given',
                    match ($val->type) {
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => 'object',
                        Variable::TYPE_RESOURCE => 'resource',
                        default => 'mixed',
                    }
                ));
            }
            $sql = self::convertValueToSql($conn, $val);
            $slot = new Variable();
            $slot->string($sql);
            $out->add($field, $slot);
        }

        return $out;
    }

    /**
     * @return string|int|false
     */
    public static function fieldTable(ObjectEntry $resultObj, int $field, bool $oidOnly): string|int|false
    {
        $result = VmPgsqlResult::native($resultObj);
        if ($field < 0) {
            throw new \ValueError('pg_field_table(): Argument #2 ($field) must be greater than or equal to 0');
        }
        if ($field >= VmPgsqlNative::nfields($result)) {
            throw new \ValueError('pg_field_table(): Argument #2 ($field) must be less than the number of fields for this result set');
        }
        $oid = VmPgsqlNative::ftable($result, $field);
        if (VmPgsqlNative::INVALID_OID === $oid) {
            return false;
        }
        if ($oidOnly) {
            return $oid;
        }
        $connObj = VmPgsqlResult::connection($resultObj);
        if (null === $connObj || !VmPgsqlConnection::isLive($connObj)) {
            return false;
        }
        $conn = VmPgsqlConnection::native($connObj);
        $tmp = VmPgsqlNative::exec($conn, 'select relname from pg_class where oid='.(int) $oid);
        if (null === $tmp || VmPgsqlNative::PGRES_TUPLES_OK !== VmPgsqlNative::resultStatus($tmp)) {
            if (null !== $tmp) {
                VmPgsqlNative::clear($tmp);
            }

            return false;
        }
        $name = VmPgsqlNative::getvalue($tmp, 0, 0);
        VmPgsqlNative::clear($tmp);

        return '' === $name ? false : $name;
    }

    public static function fieldTypeOid(ObjectEntry $resultObj, int $field): int
    {
        $result = VmPgsqlResult::native($resultObj);
        if ($field < 0) {
            throw new \ValueError('pg_field_type_oid(): Argument #2 ($field) must be greater than or equal to 0');
        }
        if ($field >= VmPgsqlNative::nfields($result)) {
            throw new \ValueError('pg_field_type_oid(): Argument #2 ($field) must be less than the number of fields for this result set');
        }

        return VmPgsqlNative::ftype($result, $field);
    }

    /**
     * @return int|false 1 if null, 0 if not, false on bad row
     */
    public static function fieldIsNull(ObjectEntry $resultObj, ?int $row, string|int $field): int|false
    {
        $result = VmPgsqlResult::native($resultObj);
        if (null === $row) {
            $pgsqlRow = VmPgsqlResult::currentRow($resultObj);
            if ($pgsqlRow < 0) {
                VmPgsqlResult::setCurrentRow($resultObj, 0);
                $pgsqlRow = 0;
            }
            if ($pgsqlRow >= VmPgsqlNative::ntuples($result)) {
                return false;
            }
        } else {
            if ($row < 0) {
                throw new \ValueError('pg_field_is_null(): Argument #2 ($row) must be greater than or equal to 0');
            }
            if ($row >= VmPgsqlNative::ntuples($result)) {
                @\trigger_error(\sprintf('pg_field_is_null(): Unable to jump to row %d on PostgreSQL result', $row), \E_USER_WARNING);

                return false;
            }
            $pgsqlRow = $row;
        }
        if (\is_string($field)) {
            $offset = VmPgsqlNative::fnumber($result, $field);
            if ($offset < 0) {
                throw new \ValueError('Argument #3 must be a field name from this result set');
            }
        } else {
            $offset = $field;
            if ($offset < 0) {
                throw new \ValueError('Argument #3 must be greater than or equal to 0');
            }
            if ($offset >= VmPgsqlNative::nfields($result)) {
                throw new \ValueError('Argument #3 must be less than the number of fields for this result set');
            }
        }

        return VmPgsqlNative::getisnull($result, $pgsqlRow, $offset) ? 1 : 0;
    }

    /**
     * pg_socket — stream resource wrapping connection fd (php-src php_stream_pgsql_fd_ops; #20636).
     *
     * @return Variable|false stream resource variable
     */
    public static function socket(ObjectEntry $connection, Context $ctx): Variable|false
    {
        $fd = VmPgsqlNative::socket(VmPgsqlConnection::native($connection));
        if ($fd < 0) {
            return false;
        }
        $var = new Variable();
        $var->streamHandle($fd, $ctx);

        return $var;
    }

    public static function consumeInput(ObjectEntry $connection): bool
    {
        return VmPgsqlNative::consumeInput(VmPgsqlConnection::native($connection));
    }

    /**
     * pg_flush — true / 0 / false (php-src ext/pgsql/pgsql.c; #20636).
     *
     * @return true|int|false
     */
    public static function flush(ObjectEntry $connection): bool|int
    {
        $conn = VmPgsqlConnection::native($connection);
        $wasNonBlocking = VmPgsqlNative::isNonBlocking($conn);
        if (0 === $wasNonBlocking && -1 === VmPgsqlNative::setNonBlocking($conn, 1)) {
            @\trigger_error('pg_flush(): Cannot set connection to nonblocking mode', \E_USER_NOTICE);

            return false;
        }
        $ret = VmPgsqlNative::flush($conn);
        if (0 === $wasNonBlocking && -1 === VmPgsqlNative::setNonBlocking($conn, 0)) {
            @\trigger_error('pg_flush(): Failed resetting connection to blocking mode', \E_USER_NOTICE);
        }

        return match ($ret) {
            0 => true,
            1 => 0,
            default => false,
        };
    }

    public static function quoteTableName(\FFI\CData $conn, string $table): string|false
    {
        $dot = \strpos($table, '.');
        if (false === $dot) {
            $esc = VmPgsqlNative::escapeIdentifier($conn, $table);

            return '' !== $esc ? $esc : false;
        }
        $schema = \substr($table, 0, $dot);
        $rel = \substr($table, $dot + 1);
        $escSchema = VmPgsqlNative::escapeIdentifier($conn, $schema);
        $escRel = VmPgsqlNative::escapeIdentifier($conn, $rel);
        if ('' === $escSchema || '' === $escRel) {
            return false;
        }

        return $escSchema.'.'.$escRel;
    }

    /**
     * @return array{sql: string}|array{error: true}|array{result: Variable}|array{ok: true}|array{rows: HashTable}|array{sql_out: string}
     */
    public static function insert(ObjectEntry $connection, string $table, HashTable $values, int $flags, Context $ctx): array
    {
        if ('' === $table) {
            throw new \ValueError('pg_insert(): Argument #2 ($table_name) must not be empty');
        }
        $conn = VmPgsqlConnection::native($connection);
        VmPgsqlNative::drainResults($conn);
        $quoted = self::quoteTableName($conn, $table);
        if (false === $quoted) {
            return ['error' => true];
        }
        $converted = $values;
        if (0 === ($flags & (PgsqlConstants::PGSQL_DML_NO_CONV | PgsqlConstants::PGSQL_DML_ESCAPE))) {
            $tmp = self::convert($connection, $table, $values, $flags & (PgsqlConstants::PGSQL_CONV_IGNORE_DEFAULT | PgsqlConstants::PGSQL_CONV_FORCE_NULL | PgsqlConstants::PGSQL_CONV_IGNORE_NOT_NULL));
            if (false === $tmp) {
                return ['error' => true];
            }
            $converted = $tmp;
        }
        $cols = [];
        $vals = [];
        $empty = true;
        foreach ($converted->iterateKeyed(true) as [$keyVar, $valVar]) {
            $empty = false;
            $field = $keyVar->resolveIndirect()->toString();
            if ('' === $field) {
                throw new \ValueError('Array of values must be an associative array with string keys');
            }
            if ($flags & PgsqlConstants::PGSQL_DML_ESCAPE) {
                $esc = VmPgsqlNative::escapeIdentifier($conn, $field);
                $cols[] = '' !== $esc ? $esc : '"'.$field.'"';
            } else {
                $cols[] = $field;
            }
            $vals[] = self::sqlLiteralFromConverted($conn, $valVar->resolveIndirect(), (bool) ($flags & PgsqlConstants::PGSQL_DML_ESCAPE));
        }
        if ($empty) {
            $sql = 'INSERT INTO '.$quoted.' DEFAULT VALUES';
        } else {
            $sql = 'INSERT INTO '.$quoted.' ('.\implode(',', $cols).') VALUES ('.\implode(',', $vals).')';
        }
        if ($flags & PgsqlConstants::PGSQL_DML_STRING && 0 === ($flags & PgsqlConstants::PGSQL_DML_EXEC)) {
            return ['sql_out' => $sql];
        }
        if ($flags & PgsqlConstants::PGSQL_DML_EXEC || 0 === ($flags & PgsqlConstants::PGSQL_DML_STRING)) {
            // Default EXEC path (Zend clears EXEC bit then runs with STRING then PQexec)
            $wantString = (bool) ($flags & PgsqlConstants::PGSQL_DML_STRING);
            $res = VmPgsqlNative::exec($conn, $sql);
            if (null === $res) {
                VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
                @\trigger_error('pg_insert(): Query failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

                return ['error' => true];
            }
            $status = VmPgsqlNative::resultStatus($res);
            if (VmPgsqlNative::PGRES_COMMAND_OK !== $status && VmPgsqlNative::PGRES_TUPLES_OK !== $status) {
                VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
                @\trigger_error('pg_insert(): Query failed: '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);
                VmPgsqlNative::clear($res);

                return ['error' => true];
            }
            if ($wantString) {
                VmPgsqlNative::clear($res);

                return ['sql_out' => $sql];
            }

            return ['result' => VmPgsqlResult::wrap($res, $ctx, $connection)];
        }

        return ['sql_out' => $sql];
    }

    /**
     * @return true|string|false
     */
    public static function update(ObjectEntry $connection, string $table, HashTable $values, HashTable $conditions, int $flags): bool|string
    {
        if ('' === $table) {
            throw new \ValueError('pg_update(): Argument #2 ($table_name) must not be empty');
        }
        $conn = VmPgsqlConnection::native($connection);
        VmPgsqlNative::drainResults($conn);
        $quoted = self::quoteTableName($conn, $table);
        if (false === $quoted) {
            return false;
        }
        $setVals = $values;
        $whereVals = $conditions;
        if (0 === ($flags & (PgsqlConstants::PGSQL_DML_NO_CONV | PgsqlConstants::PGSQL_DML_ESCAPE))) {
            $convOpts = $flags & (PgsqlConstants::PGSQL_CONV_IGNORE_DEFAULT | PgsqlConstants::PGSQL_CONV_FORCE_NULL | PgsqlConstants::PGSQL_CONV_IGNORE_NOT_NULL);
            $setVals = self::convert($connection, $table, $values, $convOpts);
            $whereVals = self::convert($connection, $table, $conditions, $convOpts);
            if (false === $setVals || false === $whereVals) {
                return false;
            }
        }
        $set = self::buildAssignmentList($conn, $setVals, ', ', (bool) ($flags & PgsqlConstants::PGSQL_DML_ESCAPE));
        $where = self::buildAssignmentList($conn, $whereVals, ' AND ', (bool) ($flags & PgsqlConstants::PGSQL_DML_ESCAPE));
        if (null === $set || null === $where) {
            return false;
        }
        $sql = 'UPDATE '.$quoted.' SET '.$set.' WHERE '.$where.';';
        if ($flags & PgsqlConstants::PGSQL_DML_STRING && 0 === ($flags & PgsqlConstants::PGSQL_DML_EXEC)) {
            return $sql;
        }
        if (!($flags & PgsqlConstants::PGSQL_DML_EXEC) && ($flags & PgsqlConstants::PGSQL_DML_STRING)) {
            return $sql;
        }
        $res = VmPgsqlNative::exec($conn, $sql);
        if (null === $res || VmPgsqlNative::PGRES_COMMAND_OK !== VmPgsqlNative::resultStatus($res)) {
            if (null !== $res) {
                VmPgsqlNative::clear($res);
            }
            @\trigger_error('pg_update(): '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

            return false;
        }
        VmPgsqlNative::clear($res);
        if ($flags & PgsqlConstants::PGSQL_DML_STRING) {
            return $sql;
        }

        return true;
    }

    /**
     * @return true|string|false
     */
    public static function delete(ObjectEntry $connection, string $table, HashTable $conditions, int $flags): bool|string
    {
        if ('' === $table) {
            throw new \ValueError('pg_delete(): Argument #2 ($table_name) must not be empty');
        }
        $conn = VmPgsqlConnection::native($connection);
        VmPgsqlNative::drainResults($conn);
        $quoted = self::quoteTableName($conn, $table);
        if (false === $quoted) {
            return false;
        }
        $whereVals = $conditions;
        if (0 === ($flags & (PgsqlConstants::PGSQL_DML_NO_CONV | PgsqlConstants::PGSQL_DML_ESCAPE))) {
            $whereVals = self::convert(
                $connection,
                $table,
                $conditions,
                $flags & (PgsqlConstants::PGSQL_CONV_IGNORE_DEFAULT | PgsqlConstants::PGSQL_CONV_FORCE_NULL | PgsqlConstants::PGSQL_CONV_IGNORE_NOT_NULL)
            );
            if (false === $whereVals) {
                return false;
            }
        }
        $where = self::buildAssignmentList($conn, $whereVals, ' AND ', (bool) ($flags & PgsqlConstants::PGSQL_DML_ESCAPE));
        if (null === $where) {
            return false;
        }
        $sql = 'DELETE FROM '.$quoted.' WHERE '.$where.';';
        if (($flags & PgsqlConstants::PGSQL_DML_STRING) && 0 === ($flags & PgsqlConstants::PGSQL_DML_EXEC)) {
            return $sql;
        }
        $res = VmPgsqlNative::exec($conn, $sql);
        if (null === $res || VmPgsqlNative::PGRES_COMMAND_OK !== VmPgsqlNative::resultStatus($res)) {
            if (null !== $res) {
                VmPgsqlNative::clear($res);
            }
            @\trigger_error('pg_delete(): '.VmPgsqlNative::errorMessage($conn), \E_USER_WARNING);

            return false;
        }
        VmPgsqlNative::clear($res);
        if ($flags & PgsqlConstants::PGSQL_DML_STRING) {
            return $sql;
        }

        return true;
    }

    /**
     * @return HashTable|string|false
     */
    public static function select(ObjectEntry $connection, string $table, ?HashTable $conditions, int $flags, int $mode): HashTable|string|false
    {
        if ('' === $table) {
            throw new \ValueError('pg_select(): Argument #2 ($table_name) must not be empty');
        }
        if (0 === ($mode & PgsqlConstants::PGSQL_BOTH)) {
            throw new \ValueError('pg_select(): Argument #5 ($mode) must be one of PGSQL_ASSOC, PGSQL_NUM, or PGSQL_BOTH');
        }
        $conn = VmPgsqlConnection::native($connection);
        VmPgsqlNative::drainResults($conn);
        $quoted = self::quoteTableName($conn, $table);
        if (false === $quoted) {
            return false;
        }
        $sql = 'SELECT * FROM '.$quoted;
        if (null !== $conditions) {
            $has = false;
            foreach ($conditions->iterateKeyed(true) as $_) {
                $has = true;
                break;
            }
            if ($has) {
                $whereVals = $conditions;
                if (0 === ($flags & (PgsqlConstants::PGSQL_DML_NO_CONV | PgsqlConstants::PGSQL_DML_ESCAPE))) {
                    $whereVals = self::convert(
                        $connection,
                        $table,
                        $conditions,
                        $flags & (PgsqlConstants::PGSQL_CONV_IGNORE_DEFAULT | PgsqlConstants::PGSQL_CONV_FORCE_NULL | PgsqlConstants::PGSQL_CONV_IGNORE_NOT_NULL)
                    );
                    if (false === $whereVals) {
                        return false;
                    }
                }
                $where = self::buildAssignmentList($conn, $whereVals, ' AND ', (bool) ($flags & PgsqlConstants::PGSQL_DML_ESCAPE));
                if (null === $where) {
                    return false;
                }
                $sql .= ' WHERE '.$where;
            }
        }
        $sql .= ';';
        if (($flags & PgsqlConstants::PGSQL_DML_STRING) && 0 === ($flags & PgsqlConstants::PGSQL_DML_EXEC)) {
            return $sql;
        }
        $res = VmPgsqlNative::exec($conn, $sql);
        if (null === $res || VmPgsqlNative::PGRES_TUPLES_OK !== VmPgsqlNative::resultStatus($res)) {
            @\trigger_error(\sprintf("pg_select(): Failed to execute '%s'", $sql), \E_USER_NOTICE);
            if (null !== $res) {
                VmPgsqlNative::clear($res);
            }

            return false;
        }
        $rows = self::resultToArray($res, $mode);
        VmPgsqlNative::clear($res);
        if ($flags & PgsqlConstants::PGSQL_DML_STRING) {
            return $sql;
        }

        return $rows;
    }

    private static function sqlLiteralFromConverted(\FFI\CData $conn, Variable $val, bool $escape): string
    {
        if (Variable::TYPE_NULL === $val->type) {
            return 'NULL';
        }
        if (Variable::TYPE_INTEGER === $val->type) {
            return (string) $val->toInt();
        }
        if (Variable::TYPE_FLOAT === $val->type) {
            return (string) $val->toFloat();
        }
        $str = $val->toString();
        if (!$escape) {
            // Already SQL literals from convert() (e.g. 't', NULL, 'foo')
            return $str;
        }
        $esc = VmPgsqlNative::escapeStringConn($conn, $str);
        if (false === $esc) {
            return "'".\str_replace("'", "''", $str)."'";
        }

        return "'".$esc."'";
    }

    private static function buildAssignmentList(\FFI\CData $conn, HashTable $ht, string $sep, bool $escape): ?string
    {
        $parts = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $valVar]) {
            $field = $keyVar->resolveIndirect()->toString();
            if ('' === $field) {
                return null;
            }
            if ($escape) {
                $col = VmPgsqlNative::escapeIdentifier($conn, $field);
                $col = '' !== $col ? $col : '"'.$field.'"';
            } else {
                $col = $field;
            }
            $parts[] = $col.' = '.self::sqlLiteralFromConverted($conn, $valVar->resolveIndirect(), $escape);
        }
        if ([] === $parts) {
            return null;
        }

        return \implode($sep, $parts);
    }

    private static function resultToArray(\FFI\CData $result, int $mode): HashTable
    {
        $out = new HashTable();
        $ntuples = VmPgsqlNative::ntuples($result);
        $nfields = VmPgsqlNative::nfields($result);
        for ($r = 0; $r < $ntuples; ++$r) {
            $row = new HashTable();
            for ($f = 0; $f < $nfields; ++$f) {
                $isNull = VmPgsqlNative::getisnull($result, $r, $f);
                $value = $isNull ? null : VmPgsqlNative::getvalue($result, $r, $f);
                if ($mode & PgsqlConstants::PGSQL_ASSOC) {
                    $slot = new Variable();
                    if ($isNull) {
                        $slot->null();
                    } else {
                        $slot->string($value);
                    }
                    $row->add(VmPgsqlNative::fname($result, $f), $slot);
                }
                if ($mode & PgsqlConstants::PGSQL_NUM) {
                    $slot = new Variable();
                    if ($isNull) {
                        $slot->null();
                    } else {
                        $slot->string($value);
                    }
                    $row->add((string) $f, $slot);
                }
            }
            $rowVar = new Variable();
            $rowVar->array($row);
            $out->add((string) $r, $rowVar);
        }

        return $out;
    }

    /**
     * @return HashTable|false
     */
    public static function fetchAssoc(ObjectEntry $resultObj): HashTable|false
    {
        $result = VmPgsqlResult::native($resultObj);
        $row = VmPgsqlResult::currentRow($resultObj);
        $ntuples = VmPgsqlNative::ntuples($result);
        if ($row >= $ntuples) {
            return false;
        }
        $ht = new HashTable();
        $nfields = VmPgsqlNative::nfields($result);
        for ($i = 0; $i < $nfields; ++$i) {
            $name = VmPgsqlNative::fname($result, $i);
            $slot = new Variable();
            if (VmPgsqlNative::getisnull($result, $row, $i)) {
                $slot->null();
            } else {
                $slot->string(VmPgsqlNative::getvalue($result, $row, $i));
            }
            $ht->add($name, $slot);
        }
        VmPgsqlResult::advanceRow($resultObj);

        return $ht;
    }

    /**
     * @return HashTable|false numeric keys
     */
    public static function fetchRow(ObjectEntry $resultObj): HashTable|false
    {
        $result = VmPgsqlResult::native($resultObj);
        $row = VmPgsqlResult::currentRow($resultObj);
        $ntuples = VmPgsqlNative::ntuples($result);
        if ($row >= $ntuples) {
            return false;
        }
        $ht = new HashTable();
        $nfields = VmPgsqlNative::nfields($result);
        for ($i = 0; $i < $nfields; ++$i) {
            $slot = new Variable();
            if (VmPgsqlNative::getisnull($result, $row, $i)) {
                $slot->null();
            } else {
                $slot->string(VmPgsqlNative::getvalue($result, $row, $i));
            }
            $ht->add((string) $i, $slot);
        }
        VmPgsqlResult::advanceRow($resultObj);

        return $ht;
    }

    private static function convertValueToSql(\FFI\CData $conn, Variable $val): string
    {
        if (Variable::TYPE_NULL === $val->type) {
            return 'NULL';
        }
        if (Variable::TYPE_BOOLEAN === $val->type) {
            return $val->toBool() ? "'t'" : "'f'";
        }
        if (Variable::TYPE_INTEGER === $val->type) {
            return (string) $val->toInt();
        }
        if (Variable::TYPE_FLOAT === $val->type) {
            return (string) $val->toFloat();
        }
        $str = $val->toString();
        if ('' === $str) {
            return 'NULL';
        }
        $escaped = VmPgsqlNative::escapeLiteral($conn, $str);

        return '' !== $escaped ? $escaped : ("'".\str_replace("'", "''", $str)."'");
    }

    /** Escape for embedding in PostgreSQL E'...' (php-src inserts raw; quote-safe). */
    private static function escapeCopyEString(string $s): string
    {
        return \str_replace("'", "''", $s);
    }

    private static function htAddLong(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable();
        $slot->int($value);
        $ht->add($key, $slot);
    }

    private static function htAddString(HashTable $ht, string $key, string $value): void
    {
        $slot = new Variable();
        $slot->string($value);
        $ht->add($key, $slot);
    }

    private static function htAddBool(HashTable $ht, string $key, bool $value): void
    {
        $slot = new Variable();
        $slot->bool($value);
        $ht->add($key, $slot);
    }
}
