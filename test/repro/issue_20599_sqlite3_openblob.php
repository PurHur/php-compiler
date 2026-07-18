<?php
/**
 * Repro #20599 — SQLite3::openBlob() BLOB stream (php-src ext/sqlite3/sqlite3.c).
 * Signature: openBlob(table, column, rowid, database="main", flags=READONLY).
 */
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, b BLOB); INSERT INTO t(b) VALUES (X\'0102\');');
echo 'openBlob=', method_exists($db, 'openBlob') ? 'yes' : 'no', PHP_EOL;
$h = $db->openBlob('t', 'b', 1, 'main', SQLITE3_OPEN_READONLY);
echo 'type=', is_resource($h) ? get_resource_type($h) : var_export($h, true), PHP_EOL;
$bin = stream_get_contents($h);
echo 'hex=', bin2hex($bin), PHP_EOL;
fclose($h);
$bad = $db->openBlob('missing', 'b', 1);
echo 'bad=', var_export($bad, true), PHP_EOL;
$db->enableExceptions(true);
try {
    $db->openBlob('missing', 'b', 1);
    echo "exc=none\n";
} catch (SQLite3Exception $e) {
    echo "exc=SQLite3Exception\n";
}
