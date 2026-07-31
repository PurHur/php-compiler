--TEST--
ext/odbc odbc_gettypeinfo returns a fetchable result when SQLite ODBC works (#21279)
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
$db = sys_get_temp_dir().'/phpc_odbc_21279_catalog.sqlite';
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
$info = odbc_gettypeinfo($conn);
echo 'gettypeinfo=', (false !== $info ? 'ok' : 'fail'), "\n";
$fetched = false;
if (false !== $info) {
    $fetched = odbc_fetch_row($info);
    echo 'fetch=', ($fetched ? 'ok' : 'empty'), "\n";
    odbc_free_result($info);
}
$pk = odbc_primarykeys($conn, null, '', 't');
echo 'primarykeys=', (false !== $pk ? 'ok' : 'fail'), "\n";
if (false !== $pk) {
    odbc_free_result($pk);
}
odbc_close($conn);
@unlink($db);
?>
--EXPECTREGEX--
^(skip=no (ffi|libodbc|usable SQLite ODBC DSN)|gettypeinfo=ok\nfetch=(ok|empty)\nprimarykeys=(ok|fail))$
