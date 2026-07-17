<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(id INTEGER, name TEXT); INSERT INTO t VALUES (7, "x"); INSERT INTO t VALUES (8, "y");');
$st = $pdo->query('SELECT id, name FROM t ORDER BY id');
echo 'has_fetchColumn=', method_exists($st, 'fetchColumn') ? 'yes' : 'no', "\n";
echo 'col0=', var_export($st->fetchColumn(0), true), "\n";
echo 'col1=', var_export($st->fetchColumn(1), true), "\n";
echo 'exhausted=', var_export($st->fetchColumn(0), true), "\n";
$st2 = $pdo->prepare('INSERT INTO t VALUES (9, "z")');
$st2->execute();
echo 'rowCount_insert=', $st2->rowCount(), "\n";
$st3 = $pdo->query('SELECT id FROM t');
echo 'rowCount_select=', $st3->rowCount(), "\n";
echo 'columnCount=', $st3->columnCount(), "\n";
echo 'close=', var_export($st3->closeCursor(), true), "\n";
$st4 = $pdo->query('SELECT id, name FROM t WHERE id=7');
$o = $st4->fetchObject();
echo 'fetchObject=', is_object($o) ? get_class($o) : var_export($o, true);
if (is_object($o)) {
    echo ' id=', $o->id, ' name=', $o->name;
}
echo "\n";
