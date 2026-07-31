--TEST--
PDOStatement::fetch/fetchAll honor FETCH_KEY_PAIR (#25640, ext/pdo/pdo_stmt.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(n TEXT, m INT)');
$pdo->exec('INSERT INTO t VALUES("a",1),("b",2)');

$map = $pdo->query('SELECT n, m FROM t')->fetchAll(PDO::FETCH_KEY_PAIR);
echo 'fetchAll_KEY_PAIR=', var_export($map, true), "\n";

$one = $pdo->query('SELECT n, m FROM t')->fetch(PDO::FETCH_KEY_PAIR);
echo 'fetch_KEY_PAIR=', var_export($one, true), "\n";

$st = $pdo->query('SELECT n, m FROM t');
$st->setFetchMode(PDO::FETCH_KEY_PAIR);
echo 'setFetchMode_KEY_PAIR=', var_export($st->fetchAll(), true), "\n";

try {
    $pdo->query('SELECT n FROM t')->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "bad_cols=no_throw\n";
} catch (PDOException $e) {
    echo 'bad_cols=', (str_contains($e->getMessage(), 'FETCH_KEY_PAIR') ? 'ok' : $e->getMessage()), "\n";
}
?>
--EXPECT--
fetchAll_KEY_PAIR=array (
  'a' => 1,
  'b' => 2,
)
fetch_KEY_PAIR=array (
  'a' => 1,
)
setFetchMode_KEY_PAIR=array (
  'a' => 1,
  'b' => 2,
)
bad_cols=ok
