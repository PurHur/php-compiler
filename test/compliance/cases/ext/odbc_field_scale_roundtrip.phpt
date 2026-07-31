--TEST--
ext/odbc odbc_field_scale soft roundtrip when SQLite ODBC works (#21306)
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
$db = sys_get_temp_dir().'/phpc_odbc_21306_scale.sqlite';
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
@odbc_exec($conn, 'CREATE TABLE t (id INTEGER, amt DECIMAL(10,2))');
$res = odbc_exec($conn, 'SELECT id, amt FROM t');
if (false === $res) {
    echo "skip=exec failed\n";
    odbc_close($conn);
    @unlink($db);
    exit(0);
}
$scale = odbc_field_scale($res, 2);
$prec = odbc_field_precision($res, 1);
echo 'scale=', (false === $scale ? 'fail' : 'ok'), "\n";
echo 'precision=', (false === $prec ? 'fail' : 'ok'), "\n";
odbc_free_result($res);
odbc_close($conn);
@unlink($db);
?>
--EXPECTREGEX--
^(skip=no (ffi|libodbc|usable SQLite ODBC DSN)|skip=exec failed|scale=(ok|fail)\nprecision=(ok|fail))$
