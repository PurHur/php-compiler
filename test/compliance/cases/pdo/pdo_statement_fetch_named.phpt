--TEST--
PDOStatement::fetch/fetchAll honor FETCH_NAMED (#25666, ext/pdo/pdo_stmt.c)
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE u(id INT, name TEXT)');
$pdo->exec('INSERT INTO u VALUES(1,"a"),(2,"b")');

echo 'fetchAll_NAMED=', var_export(
    $pdo->query('SELECT name AS n, name AS n FROM u')->fetchAll(PDO::FETCH_NAMED),
    true
), "\n";

echo 'fetch_NAMED=', var_export(
    $pdo->query('SELECT name AS n, name AS n FROM u')->fetch(PDO::FETCH_NAMED),
    true
), "\n";

echo 'fetchAll_unique_cols=', var_export(
    $pdo->query('SELECT id, name FROM u')->fetchAll(PDO::FETCH_NAMED),
    true
), "\n";

$st = $pdo->query('SELECT name AS n, name AS n FROM u');
$st->setFetchMode(PDO::FETCH_NAMED);
echo 'setFetchMode_NAMED=', var_export($st->fetchAll(), true), "\n";
?>
--EXPECT--
fetchAll_NAMED=array (
  0 => array (
    'n' => array (
      0 => 'a',
      1 => 'a',
    ),
  ),
  1 => array (
    'n' => array (
      0 => 'b',
      1 => 'b',
    ),
  ),
)
fetch_NAMED=array (
  'n' => array (
    0 => 'a',
    1 => 'a',
  ),
)
fetchAll_unique_cols=array (
  0 => array (
    'id' => 1,
    'name' => 'a',
  ),
  1 => array (
    'id' => 2,
    'name' => 'b',
  ),
)
setFetchMode_NAMED=array (
  0 => array (
    'n' => array (
      0 => 'a',
      1 => 'a',
    ),
  ),
  1 => array (
    'n' => array (
      0 => 'b',
      1 => 'b',
    ),
  ),
)
