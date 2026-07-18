<?php
// Repro for #20566 — pdo_pgsql + PDO::pgsql* extension methods
declare(strict_types=1);

echo extension_loaded('pdo_pgsql') ? "ext yes\n" : "ext NO\n";
echo in_array('pdo_pgsql', get_loaded_extensions(), true) ? "in_list yes\n" : "in_list NO\n";

foreach (['pgsqlCopyFromArray', 'pgsqlCopyToArray', 'pgsqlGetNotify', 'pgsqlGetPid', 'sqliteCreateFunction'] as $m) {
    // String class name (PDO::class under JIT is an unrelated NestedJit crash).
    echo $m, ': ', method_exists('PDO', $m) ? "yes\n" : "NO\n";
}

// Call path: no live libpq yet (#3741) — methods exist and throw could not find driver.
$pdo = new PDO('sqlite::memory:');
try {
    $pdo->pgsqlGetPid();
    echo "pgsqlGetPid_call=OK\n";
} catch (PDOException $e) {
    echo 'pgsqlGetPid_call=', $e->getMessage(), "\n";
}
try {
    $pdo->pgsqlGetNotify();
    echo "pgsqlGetNotify_call=OK\n";
} catch (PDOException $e) {
    echo 'pgsqlGetNotify_call=', $e->getMessage(), "\n";
}
try {
    $pdo->pgsqlCopyFromArray('t', [['a']]);
    echo "pgsqlCopyFromArray_call=OK\n";
} catch (PDOException $e) {
    echo 'pgsqlCopyFromArray_call=', $e->getMessage(), "\n";
}

// Still not in getAvailableDrivers until a real factory exists.
$drivers = PDO::getAvailableDrivers();
echo 'has_pgsql_driver=', var_export(in_array('pgsql', $drivers, true), true), "\n";
