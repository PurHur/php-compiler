--TEST--
stdlib PDOStatement::fetchColumn/rowCount/closeCursor (#19838, ext/pdo/pdo_stmt.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(id INTEGER, name TEXT); INSERT INTO t VALUES (7, "x"); INSERT INTO t VALUES (8, "y");');
$st = $pdo->query('SELECT id, name FROM t ORDER BY id');
echo 'has_fetchColumn=', method_exists($st, 'fetchColumn') ? 'yes' : 'no', "\n";
echo 'col0=', $st->fetchColumn(0), "\n";
echo 'col1=', $st->fetchColumn(1), "\n";
echo 'exhausted=', var_export($st->fetchColumn(0), true), "\n";
$ins = $pdo->prepare('INSERT INTO t VALUES (9, "z")');
$ins->execute();
echo 'rowCount_insert=', $ins->rowCount(), "\n";
$sel = $pdo->query('SELECT id FROM t');
echo 'rowCount_select=', $sel->rowCount(), "\n";
echo 'columnCount=', $sel->columnCount(), "\n";
echo 'close=', var_export($sel->closeCursor(), true), "\n";
$row = $pdo->query('SELECT id, name FROM t WHERE id=7')->fetchObject();
echo 'obj=', $row->id, ':', $row->name, "\n";
?>
--EXPECT--
has_fetchColumn=yes
col0=7
col1=y
exhausted=false
rowCount_insert=1
rowCount_select=0
columnCount=1
close=true
obj=7:x
