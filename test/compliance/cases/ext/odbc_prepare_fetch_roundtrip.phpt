--TEST--
ext/odbc prepare+execute+fetch_array round-trip via SQLite ODBC (#21258)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--SKIPIF--
<?php
if (!extension_loaded('ffi')) die('skip no ffi');
if (!function_exists('odbc_prepare')) die('skip no odbc_prepare');
$haveLib = false;
foreach (['libodbc.so.2', 'libodbc.so.1', 'libodbc.so'] as $lib) {
    try {
        \FFI::cdef('typedef void *SQLHENV; typedef unsigned char SQLCHAR; typedef short SQLRETURN; SQLRETURN SQLAllocEnv(SQLHENV *);', $lib);
        $haveLib = true;
        break;
    } catch (Throwable $e) {
    }
}
if (!$haveLib) die('skip no libodbc');
if (!is_file('/usr/lib/x86_64-linux-gnu/odbc/libsqlite3odbc.so')
    && !is_file('/usr/lib/x86_64-linux-gnu/odbc/libsqliteodbc.so')
) {
    die('skip no SQLite ODBC driver');
}
?>
--FILE--
<?php
declare(strict_types=1);
$db = sys_get_temp_dir().'/phpc_odbc_21258_rt.sqlite';
@unlink($db);
$dsn = 'Driver=SQLite3;Database='.$db;
$conn = odbc_connect($dsn, '', '');
if (false === $conn) {
    $conn = odbc_connect('Driver=SQLite;Database='.$db, '', '');
}
if (false === $conn) {
    echo "connect=fail\n";
    exit(0);
}
$ok = odbc_exec($conn, 'CREATE TABLE t (id INTEGER, name VARCHAR(32))');
echo 'create=', (false !== $ok ? 'ok' : 'fail'), "\n";
if (false !== $ok) {
    odbc_free_result($ok);
}
$ins = odbc_prepare($conn, 'INSERT INTO t (id, name) VALUES (?, ?)');
echo 'prepare_ins=', (false !== $ins ? 'ok' : 'fail'), "\n";
if (false !== $ins) {
    echo 'execute_ins=', (odbc_execute($ins, [1, 'alice']) ? 'ok' : 'fail'), "\n";
    odbc_free_result($ins);
}
$sel = odbc_prepare($conn, 'SELECT id, name FROM t');
echo 'prepare_sel=', (false !== $sel ? 'ok' : 'fail'), "\n";
if (false !== $sel) {
    echo 'execute_sel=', (odbc_execute($sel) ? 'ok' : 'fail'), "\n";
    echo 'num_fields=', odbc_num_fields($sel), "\n";
    echo 'field_name1=', var_export(odbc_field_name($sel, 1), true), "\n";
    $row = odbc_fetch_array($sel);
    echo 'fetch_array=', (false !== $row && isset($row['name']) ? $row['name'] : 'fail'), "\n";
    odbc_free_result($sel);
}
$tabs = odbc_tables($conn);
echo 'tables=', (false !== $tabs ? 'ok' : 'fail'), "\n";
if (false !== $tabs) {
    odbc_free_result($tabs);
}
$cols = odbc_columns($conn, null, null, 't');
echo 'columns=', (false !== $cols ? 'ok' : 'fail'), "\n";
if (false !== $cols) {
    odbc_free_result($cols);
}
odbc_close($conn);
@unlink($db);
?>
--EXPECT--
create=ok
prepare_ins=ok
execute_ins=ok
prepare_sel=ok
execute_sel=ok
num_fields=2
field_name1='id'
fetch_array=alice
tables=ok
columns=ok
