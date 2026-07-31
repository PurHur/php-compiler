<?php
/**
 * Repro #24129 — PGSQL ExecStatusType / SEEK_* / LIBPQ_VERSION* vs Zend.
 * Bare constant use so AOT can resolve Module-registered consts (#24129).
 */
declare(strict_types=1);

echo 'PGSQL_EMPTY_QUERY=', PGSQL_EMPTY_QUERY, "\n";
echo 'PGSQL_COMMAND_OK=', PGSQL_COMMAND_OK, "\n";
echo 'PGSQL_TUPLES_OK=', PGSQL_TUPLES_OK, "\n";
echo 'PGSQL_COPY_OUT=', PGSQL_COPY_OUT, "\n";
echo 'PGSQL_COPY_IN=', PGSQL_COPY_IN, "\n";
echo 'PGSQL_BAD_RESPONSE=', PGSQL_BAD_RESPONSE, "\n";
echo 'PGSQL_NONFATAL_ERROR=', PGSQL_NONFATAL_ERROR, "\n";
echo 'PGSQL_FATAL_ERROR=', PGSQL_FATAL_ERROR, "\n";
echo 'PGSQL_SEEK_SET=', PGSQL_SEEK_SET, "\n";
echo 'PGSQL_SEEK_CUR=', PGSQL_SEEK_CUR, "\n";
echo 'PGSQL_SEEK_END=', PGSQL_SEEK_END, "\n";
echo 'PGSQL_LIBPQ_VERSION=', PGSQL_LIBPQ_VERSION, "\n";
echo 'PGSQL_LIBPQ_VERSION_STR=', PGSQL_LIBPQ_VERSION_STR, "\n";
