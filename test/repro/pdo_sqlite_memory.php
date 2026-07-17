<?php

/**
 * Issue #3367 — PDO sqlite::memory: connect/prepare/query subset.
 */
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
$st = $pdo->prepare('INSERT INTO t (v) VALUES (?)');
$st->execute(['hello']);
foreach ($pdo->query('SELECT v FROM t') as $row) {
    echo $row['v'], "\n";
}
