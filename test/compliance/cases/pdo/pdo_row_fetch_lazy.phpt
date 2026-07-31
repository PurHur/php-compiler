--TEST--
stdlib PDORow class + FETCH_LAZY (#22294, ext/pdo/pdo_stmt.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
echo 'class_exists=', class_exists('PDORow') ? '1' : '0', "\n";
echo 'methods=', (string) count(get_class_methods('PDORow')), "\n";

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(a INT, b TEXT)');
$pdo->exec('INSERT INTO t VALUES(1, "x")');
$st = $pdo->query('SELECT a, b FROM t');
$row = $st->fetch(PDO::FETCH_LAZY);
echo 'is_pdorow=', ($row instanceof PDORow) ? '1' : '0', "\n";
echo 'a=', is_object($row) ? (string) $row->a : '', "\n";
echo 'b=', is_object($row) ? (string) $row->b : '', "\n";
echo 'qs_has=', (is_object($row) && isset($row->queryString) && is_string($row->queryString)) ? '1' : '0', "\n";
?>
--EXPECT--
class_exists=1
methods=0
is_pdorow=1
a=1
b=x
qs_has=1
