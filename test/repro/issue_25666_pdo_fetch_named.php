<?php
/** Repro #25666 — FETCH_NAMED nests duplicate column names (php-src pdo_stmt.c). */
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE u(id INT, name TEXT)');
$pdo->exec('INSERT INTO u VALUES(1,"a"),(2,"b")');

$all = $pdo->query('SELECT name AS n, name AS n FROM u')->fetchAll(PDO::FETCH_NAMED);
echo 'fetchAll_NAMED=', var_export($all, true), "\n";

$one = $pdo->query('SELECT name AS n, name AS n FROM u')->fetch(PDO::FETCH_NAMED);
echo 'fetch_NAMED=', var_export($one, true), "\n";

$uniq = $pdo->query('SELECT id, name FROM u')->fetchAll(PDO::FETCH_NAMED);
echo 'fetchAll_unique_cols=', var_export($uniq, true), "\n";
