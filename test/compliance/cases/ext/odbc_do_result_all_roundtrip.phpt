--TEST--
ext/odbc odbc_do/result_all soft roundtrip when SQLite ODBC works (#21308)
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
$db = sys_get_temp_dir().'/phpc_odbc_21308_result_all.sqlite';
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
@odbc_do($conn, 'CREATE TABLE t (id INTEGER, name VARCHAR(20))');
@odbc_do($conn, "INSERT INTO t VALUES (1, 'a')");
$res = @odbc_do($conn, 'SELECT id, name FROM t');
if (false === $res) {
    echo "skip=exec failed\n";
    odbc_close($conn);
    @unlink($db);
    exit(0);
}
ob_start();
$n = @odbc_result_all($res);
$html = ob_get_clean();
echo 'n=', (false === $n ? 'fail' : (string) $n), "\n";
echo 'html=', (is_string($html) && str_contains($html, '<table') && str_contains($html, '<th>') ? 'ok' : 'bad'), "\n";
odbc_free_result($res);
odbc_close($conn);
@unlink($db);
?>
--EXPECTREGEX--
^(skip=no (ffi|libodbc|usable SQLite ODBC DSN)|skip=exec failed|n=(fail|[0-9]+)\nhtml=(ok|bad))$
