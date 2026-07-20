<?php
/**
 * Live repro for #21278 — requires unixODBC + SQLite ODBC driver.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21278_odbc_result_helpers.php
 */
declare(strict_types=1);

foreach (['odbc_next_result', 'odbc_data_source', 'odbc_binmode', 'odbc_longreadlen'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

$db = sys_get_temp_dir().'/phpc_odbc_21278_repro.sqlite';
@unlink($db);
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
if (null === $driver) {
    echo "connect=fail (install unixodbc + libsqliteodbc to exercise live path)\n";
    exit(0);
}
$conn = odbc_connect('Driver='.$driver.';Database='.$db, '', '');
if (false === $conn) {
    echo "connect=fail\n";
    exit(0);
}

$first = odbc_data_source($conn, SQL_FETCH_FIRST);
echo 'data_source_first=', gettype($first), "\n";
if (\is_array($first)) {
    echo 'server=', $first['server'] ?? '', "\n";
    echo 'description_len=', strlen((string) ($first['description'] ?? '')), "\n";
} elseif (null === $first) {
    echo "data_source_first=null (no system DSNs)\n";
}

$res = odbc_exec($conn, 'SELECT 1 AS one');
echo 'binmode=', var_export(odbc_binmode($res, 1), true), "\n";
echo 'longreadlen=', var_export(odbc_longreadlen($res, 4096), true), "\n";
echo 'next_result=', var_export(odbc_next_result($res), true), "\n";
odbc_free_result($res);
odbc_close($conn);
@unlink($db);
