<?php
/**
 * AOT: SQLite3::prepare/query leftover of open (#36010 / #36001).
 * php-src: ext/sqlite3/sqlite3.c zim_sqlite3_prepare / zim_sqlite3_query
 */
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(v INTEGER); INSERT INTO t VALUES (42);');
$st = $db->prepare('SELECT 1');
echo 'stmt=', get_class($st), "\n";
echo 'sql=', $st->getSQL(), "\n";
$res = $db->query('SELECT v FROM t');
echo 'res=', get_class($res), "\n";
var_dump($res->fetchArray(SQLITE3_NUM));
var_dump($res->fetchArray(SQLITE3_NUM));
