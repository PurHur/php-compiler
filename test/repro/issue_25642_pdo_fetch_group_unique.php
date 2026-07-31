<?php
/** Repro #25642 — FETCH_GROUP / FETCH_UNIQUE on fetchAll (php-src pdo_stmt.c). */
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(g TEXT,n TEXT)');
$pdo->exec('INSERT INTO t VALUES("x","a"),("x","b"),("y","c")');

$g = $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP);
echo 'ASSOC_GROUP=', var_export($g, true), "\n";

$u = $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
echo 'ASSOC_UNIQUE=', var_export($u, true), "\n";

$c = $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP);
echo 'COLUMN_GROUP=', var_export($c, true), "\n";

$cu = $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE);
echo 'COLUMN_UNIQUE=', var_export($cu, true), "\n";
