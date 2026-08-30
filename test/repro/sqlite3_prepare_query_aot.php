<?php
/**
 * AOT: SQLite3::prepare/query leftover of open (#36010 / #36001).
 * php-src: ext/sqlite3/sqlite3.c zim_sqlite3_prepare / zim_sqlite3_query
 */
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(v INTEGER); INSERT INTO t VALUES (7),(8);');

$st = $db->prepare('SELECT 1');
echo 'prep_class=', get_class($st), "\n";
echo 'prep_sql=', $st->getSQL(), "\n";

$res = $db->query('SELECT v FROM t');
echo 'query_class=', get_class($res), "\n";
$row = $res->fetchArray(SQLITE3_NUM);
echo 'row0=', var_export($row[0] ?? null, true), "\n";
