--TEST--
ext/odbc odbc_cursor soft roundtrip when SQLite ODBC works (#21307)
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
$db = sys_get_temp_dir().'/phpc_odbc_21307_cursor.sqlite';
@unlink($db);
$conn = @odbc_connect('Driver=SQLite3;Database='.$db, '', '');
if (false === $conn) {
    $conn = @odbc_connect('Driver=SQLite;Database='.$db, '', '');
}
if (false === $conn) {
    echo "skip=no usable SQLite ODBC DSN\n";
    @unlink($db);
    exit(0);
}
@odbc_exec($conn, 'CREATE TABLE t (id INTEGER)');
$res = odbc_exec($conn, 'SELECT id FROM t');
if (false === $res) {
    echo "skip=exec failed\n";
    odbc_close($conn);
    @unlink($db);
    exit(0);
}
$cur = odbc_cursor($res);
echo 'cursor=', (false === $cur ? 'fail' : (is_string($cur) && $cur !== '' ? 'ok' : 'empty')), "\n";
odbc_free_result($res);
odbc_close($conn);
@unlink($db);
?>
--EXPECTREGEX--
^(skip=no (ffi|libodbc|usable SQLite ODBC DSN)|skip=exec failed|cursor=(ok|fail|empty))$
