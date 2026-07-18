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

        return VmPgsqlResult::wrap($result, $ctx);
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
}
