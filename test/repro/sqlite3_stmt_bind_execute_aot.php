<?php
// #36018 — SQLite3Stmt::bindValue/execute must match Zend under thin AOT (#36010 leftover).
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(v TEXT);');
$st = $db->prepare('INSERT INTO t VALUES (?)');
$st->bindValue(1, 'c');
$r2 = $st->execute();
echo 'prep_ok=', ($r2 instanceof SQLite3Result) ? '1' : '0', "\n";
