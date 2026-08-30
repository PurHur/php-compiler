<?php
/**
 * AOT: SQLite3 multi-row INSERT lastInsertRowID/changes/querySingle leftover of #35931 (#35956).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3_exec / lastInsertRowID / changes / querySingle
 */
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY)');
var_dump($db->exec('INSERT INTO t VALUES (10),(20)'));
echo 'pk last=', $db->lastInsertRowID(), ' changes=', $db->changes(), "\n";
echo 'count=', var_export($db->querySingle('SELECT COUNT(*) FROM t'), true), "\n";
echo 'sum=', var_export($db->querySingle('SELECT SUM(id) FROM t'), true), "\n";
echo 'first=', var_export($db->querySingle('SELECT id FROM t'), true), "\n";
$db->close();

$db2 = new SQLite3(':memory:');
$db2->exec('CREATE TABLE u(id INTEGER)');
$db2->exec('INSERT INTO u VALUES (10),(20)');
echo 'nopk last=', $db2->lastInsertRowID(), ' changes=', $db2->changes(), "\n";
$db2->close();
