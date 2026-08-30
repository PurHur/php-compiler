<?php
/**
 * AOT: SQLite3 lastInsertRowID/changes leftover of construct/exec/querySingle (#35914).
 */
$db = new SQLite3(':memory:');
$ok = $db->exec('CREATE TABLE t(x); INSERT INTO t VALUES (42);');
var_dump($ok);
var_dump($db->lastInsertRowID());
var_dump($db->changes());
var_dump($db->querySingle('SELECT x FROM t'));
