<?php
// #36010 — SQLite3::prepare/query NestedJIT leftover of #36001
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO t(id) VALUES (7)');
$st = $db->prepare('SELECT id FROM t WHERE id = ?');
echo get_class($st), "\n";
echo $st->getSQL(), "\n";
echo $st->paramCount(), "\n";
$r = $db->query('SELECT id FROM t');
echo get_class($r), "\n";
var_export($r->fetchArray(SQLITE3_NUM));
echo "\n";
var_export($r->fetchArray(SQLITE3_NUM));
echo "\n";
