--TEST--
ext/odbc odbc_procedures soft roundtrip when SQLite ODBC works (#21294)
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
$db = sys_get_temp_dir().'/phpc_odbc_21294_catalog.sqlite';
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
$procs = odbc_procedures($conn);
echo 'procedures=', (false !== $procs ? 'ok' : 'fail'), "\n";
if (false !== $procs) {
    odbc_free_result($procs);
}
$cols = odbc_procedurecolumns($conn);
echo 'procedurecolumns=', (false !== $cols ? 'ok' : 'fail'), "\n";
if (false !== $cols) {
    odbc_free_result($cols);
}
// SQL_BEST_ROWID=1, SQL_SCOPE_CURROW=0, SQL_NULLABLE=1 (sqlext.h)
$spec = odbc_specialcolumns($conn, 1, null, '', 't', 0, 1);
echo 'specialcolumns=', (false !== $spec ? 'ok' : 'fail'), "\n";
if (false !== $spec) {
    odbc_free_result($spec);
}
odbc_close($conn);
@unlink($db);
?>
--EXPECTREGEX--
^(skip=no (ffi|libodbc|usable SQLite ODBC DSN)|procedures=(ok|fail)\nprocedurecolumns=(ok|fail)\nspecialcolumns=(ok|fail))$
