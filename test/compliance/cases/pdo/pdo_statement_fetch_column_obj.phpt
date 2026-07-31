--TEST--
PDOStatement::fetch/fetchAll honor FETCH_COLUMN and FETCH_OBJ (#25578, ext/pdo/pdo_stmt.c)
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(n TEXT)');
$pdo->exec('INSERT INTO t VALUES("a"),("b")');

$cols = $pdo->query('SELECT n FROM t')->fetchAll(PDO::FETCH_COLUMN);
echo 'fetchAll_COLUMN=', var_export($cols, true), "\n";

$obj = $pdo->query('SELECT n FROM t')->fetch(PDO::FETCH_OBJ);
echo 'fetch_OBJ_type=', gettype($obj), "\n";
echo 'fetch_OBJ_class=', $obj instanceof stdClass ? 'stdClass' : gettype($obj), "\n";
echo 'fetch_OBJ_n=', $obj->n, "\n";

$objs = $pdo->query('SELECT n FROM t')->fetchAll(PDO::FETCH_OBJ);
echo 'fetchAll_OBJ0=', get_class($objs[0]), ':', $objs[0]->n, "\n";
echo 'fetchAll_OBJ1=', get_class($objs[1]), ':', $objs[1]->n, "\n";

$col0 = $pdo->query('SELECT n FROM t')->fetch(PDO::FETCH_COLUMN);
echo 'fetch_COLUMN=', var_export($col0, true), "\n";

$pdo->exec('CREATE TABLE u(n TEXT, m INT)');
$pdo->exec('INSERT INTO u VALUES("a",1),("b",2)');
$cols1 = $pdo->query('SELECT n, m FROM u')->fetchAll(PDO::FETCH_COLUMN, 1);
echo 'fetchAll_COLUMN_1=', var_export($cols1, true), "\n";

$st = $pdo->query('SELECT n, m FROM u');
$st->setFetchMode(PDO::FETCH_COLUMN, 1);
echo 'setFetchMode_COLUMN_1=', var_export($st->fetch(), true), "\n";

$st2 = $pdo->query('SELECT n FROM t');
$st2->setFetchMode(PDO::FETCH_OBJ);
$o = $st2->fetch();
echo 'setFetchMode_OBJ=', get_class($o), ':', $o->n, "\n";
?>
--EXPECT--
fetchAll_COLUMN=array (
  0 => 'a',
  1 => 'b',
)
fetch_OBJ_type=object
fetch_OBJ_class=stdClass
fetch_OBJ_n=a
fetchAll_OBJ0=stdClass:a
fetchAll_OBJ1=stdClass:b
fetch_COLUMN='a'
fetchAll_COLUMN_1=array (
  0 => 1,
  1 => 2,
)
setFetchMode_COLUMN_1=1
setFetchMode_OBJ=stdClass:a
