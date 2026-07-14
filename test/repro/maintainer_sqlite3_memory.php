<?php

declare(strict_types=1);

// Issue #3434 — SQLite3 :memory: connect/exec/querySingle (ext/sqlite3/sqlite3.c).
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_sqlite3_memory.php

if (!extension_loaded('sqlite3')) {
    fwrite(STDERR, "fail: sqlite3 extension not loaded\n");
    exit(1);
}
if (!class_exists('SQLite3', false)) {
    fwrite(STDERR, "fail: SQLite3 class missing\n");
    exit(1);
}

$db = new SQLite3(':memory:');
if (!$db->exec('CREATE TABLE t (v TEXT)')) {
    fwrite(STDERR, "fail: CREATE TABLE\n");
    exit(1);
}
if (!$db->exec("INSERT INTO t VALUES ('ok')")) {
    fwrite(STDERR, "fail: INSERT\n");
    exit(1);
}
$result = $db->querySingle('SELECT v FROM t');
if ('ok' !== $result) {
    fwrite(STDERR, 'fail: querySingle='.var_export($result, true)."\n");
    exit(1);
}
if (!$db->close()) {
    fwrite(STDERR, "fail: close\n");
    exit(1);
}

echo "ok\n";
