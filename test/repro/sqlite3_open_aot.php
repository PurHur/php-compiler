<?php
/**
 * AOT: SQLite3::open leftover of version (#36001 / #35991).
 * php-src: ext/sqlite3/sqlite3.c zim_sqlite3_open — Already initialised DB Object
 */
$db = new SQLite3(':memory:');
try {
    $db->open(':memory:');
    echo "no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo 'close=', var_export($db->close(), true), "\n";
try {
    $r = $db->open(':memory:');
    echo 'reopen=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'reopen-ex:', get_class($e), ':', $e->getMessage(), "\n";
}
$db->exec('CREATE TABLE t(x); INSERT INTO t VALUES (7);');
echo 'q=', var_export($db->querySingle('SELECT x FROM t'), true), "\n";
