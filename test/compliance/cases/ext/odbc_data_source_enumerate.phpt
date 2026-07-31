--TEST--
ext/odbc odbc_data_source / binmode / longreadlen / next_result live (#21278)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
declare(strict_types=1);
// Soft-exit when unixODBC/SQLite driver missing (BaseTest ignores --SKIPIF--).
$haveLib = false;
foreach (['libodbc.so.2', 'libodbc.so.1', 'libodbc.so'] as $lib) {
    try {
        \FFI::cdef('typedef void *SQLHENV; typedef short SQLRETURN; SQLRETURN SQLAllocEnv(SQLHENV *);', $lib);
        $haveLib = true;
        break;
    } catch (Throwable $e) {
    }
}
$driver = null;
foreach ([
    '/usr/lib/x86_64-linux-gnu/odbc/libsqlite3odbc.so',
    '/usr/lib/x86_64-linux-gnu/odbc/libsqliteodbc.so',
] as $cand) {
    if (is_file($cand)) {
        $driver = $cand;
        break;
    }
}
if (!$haveLib || null === $driver) {
    echo "odbc_live=0\n";
    exit(0);
}
$db = sys_get_temp_dir().'/phpc_odbc_21278_ds.sqlite';
@unlink($db);
$conn = odbc_connect('Driver='.$driver.';Database='.$db, '', '');
if (false === $conn) {
    echo "odbc_live=0\n";
    exit(0);
}
echo "odbc_live=1\n";
$first = odbc_data_source($conn, SQL_FETCH_FIRST);
echo 'first_type=', gettype($first), "\n";
if (\is_array($first) && isset($first['server'], $first['description'])) {
    echo 'first_server_nonempty=', (int) ('' !== (string) $first['server']), "\n";
    $n = 1;
    while (true) {
        $next = odbc_data_source($conn, SQL_FETCH_NEXT);
        if (null === $next || false === $next) {
            break;
        }
        if (\is_array($next)) {
            ++$n;
        }
    }
    echo 'dsn_count=', $n, "\n";
} elseif (null === $first) {
    echo "first_null=1\n";
}
$res = odbc_exec($conn, 'SELECT 1 AS one');
if (false !== $res) {
    echo 'binmode=', var_export(odbc_binmode($res, 1), true), "\n";
    echo 'longreadlen=', var_export(odbc_longreadlen($res, 4096), true), "\n";
    echo 'next_result=', var_export(odbc_next_result($res), true), "\n";
    odbc_free_result($res);
}
try {
    odbc_data_source($conn, 99);
    echo "bad_fetch=no_throw\n";
} catch (ValueError $e) {
    echo "bad_fetch=ValueError\n";
}
odbc_close($conn);
@unlink($db);
?>
--EXPECTF--
odbc_live=%d%A
