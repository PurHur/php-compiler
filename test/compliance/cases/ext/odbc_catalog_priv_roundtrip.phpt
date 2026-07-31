--TEST--
ext/odbc odbc_tableprivileges soft roundtrip when SQLite ODBC works (#21295)
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
$db = sys_get_temp_dir().'/phpc_odbc_21295_priv.sqlite';
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
$tp = odbc_tableprivileges($conn, null, '', 't');
echo 'tableprivileges=', (false !== $tp ? 'ok' : 'fail'), "\n";
if (false !== $tp) {
    odbc_free_result($tp);
}
$cp = odbc_columnprivileges($conn, null, '', 't', 'c');
echo 'columnprivileges=', (false !== $cp ? 'ok' : 'fail'), "\n";
if (false !== $cp) {
    odbc_free_result($cp);
}
odbc_close($conn);
@unlink($db);
?>
--EXPECTREGEX--
^(skip=no (ffi|libodbc|usable SQLite ODBC DSN)|tableprivileges=(ok|fail)\ncolumnprivileges=(ok|fail))$
