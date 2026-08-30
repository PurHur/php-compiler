<?php
/**
 * AOT: SQLite3 construct/exec/querySingle leftover of #20565 (#35914).
 */
$db = new SQLite3(':memory:');
$ok = $db->exec('CREATE TABLE t(x); INSERT INTO t VALUES (42);');
var_dump($ok);
var_dump($db->querySingle('SELECT x FROM t'));
