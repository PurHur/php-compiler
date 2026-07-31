--TEST--
ext/odbc autocommit off → insert → rollback leaves table empty (#21277)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
declare(strict_types=1);
if (!extension_loaded('ffi')) {
    echo "skip=no ffi\n";
    exit(0);
}
$haveLib = false;
foreach (['libodbc.so.2', 'libodbc.so.1', 'libodbc.so'] as $lib) {
    try {
        \FFI::cdef('typedef void *SQLHENV; typedef unsigned char SQLCHAR; typedef short SQLRETURN; SQLRETURN SQLAllocEnv(SQLHENV *);', $lib);
        $haveLib = true;
        break;
    } catch (Throwable $e) {
    }
}
if (!$haveLib) {
    echo "skip=no libodbc\n";
    exit(0);
}
$db = sys_get_temp_dir().'/phpc_odbc_21277_txn.sqlite';
@unlink($db);
$dsn = 'Driver=SQLite3;Database='.$db;
$conn = @odbc_connect($dsn, '', '');
if (false === $conn) {
    $conn = @odbc_connect('Driver=SQLite;Database='.$db, '', '');
}
if (false === $conn) {
    echo "skip=no usable SQLite ODBC DSN\n";
    @unlink($db);
    exit(0);
}
$ok = odbc_exec($conn, 'CREATE TABLE t (id INTEGER PRIMARY KEY, name VARCHAR(32))');
echo 'create=', (false !== $ok ? 'ok' : 'fail'), "\n";
if (false !== $ok) {
    odbc_free_result($ok);
}
echo 'ac_off=', (odbc_autocommit($conn, false) ? 'ok' : 'fail'), "\n";
$status = odbc_autocommit($conn);
echo 'ac_status=', var_export($status, true), "\n";
$ins = odbc_exec($conn, "INSERT INTO t (id, name) VALUES (1, 'bob')");
echo 'insert=', (false !== $ins ? 'ok' : 'fail'), "\n";
if (false !== $ins) {
    odbc_free_result($ins);
}
echo 'rollback=', (odbc_rollback($conn) ? 'ok' : 'fail'), "\n";
$sel = odbc_exec($conn, 'SELECT COUNT(*) AS c FROM t');
$countAfterRollback = 'fail';
if (false !== $sel) {
    if (odbc_fetch_row($sel)) {
        $countAfterRollback = (string) odbc_result($sel, 1);
    }
    odbc_free_result($sel);
}
echo 'count_after_rollback=', $countAfterRollback, "\n";
$ins2 = odbc_exec($conn, "INSERT INTO t (id, name) VALUES (2, 'carol')");
echo 'insert2=', (false !== $ins2 ? 'ok' : 'fail'), "\n";
if (false !== $ins2) {
    odbc_free_result($ins2);
}
echo 'commit=', (odbc_commit($conn) ? 'ok' : 'fail'), "\n";
$sel2 = odbc_exec($conn, 'SELECT COUNT(*) AS c FROM t');
$countAfterCommit = 'fail';
if (false !== $sel2) {
    if (odbc_fetch_row($sel2)) {
        $countAfterCommit = (string) odbc_result($sel2, 1);
    }
    odbc_free_result($sel2);
}
echo 'count_after_commit=', $countAfterCommit, "\n";
odbc_autocommit($conn, true);
odbc_close($conn);
@unlink($db);
?>
--EXPECTREGEX--
^(skip=no (ffi|libodbc|usable SQLite ODBC DSN)|create=ok\nac_off=ok\nac_status=0\ninsert=ok\nrollback=ok\ncount_after_rollback=0\ninsert2=ok\ncommit=ok\ncount_after_commit=1)$
