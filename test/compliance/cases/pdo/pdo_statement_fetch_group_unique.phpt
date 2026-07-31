--TEST--
PDOStatement::fetchAll honors FETCH_GROUP / FETCH_UNIQUE (#25642, ext/pdo/pdo_stmt.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(g TEXT,n TEXT,m INT)');
$pdo->exec('INSERT INTO t VALUES("x","a",1),("x","b",2),("y","c",3)');

echo 'ASSOC_GROUP=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP),
    true
), "\n";

echo 'BOTH_GROUP=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_BOTH | PDO::FETCH_GROUP),
    true
), "\n";

echo 'NUM_GROUP=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_NUM | PDO::FETCH_GROUP),
    true
), "\n";

echo 'COLUMN_GROUP=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP),
    true
), "\n";

echo 'ASSOC_UNIQUE=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE),
    true
), "\n";

echo 'COLUMN_UNIQUE=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE),
    true
), "\n";

echo 'OBJ_GROUP=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_OBJ | PDO::FETCH_GROUP),
    true
), "\n";

echo 'ASSOC_GROUP_3COL=', var_export(
    $pdo->query('SELECT g,n,m FROM t')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP),
    true
), "\n";

echo 'GROUP_ALONE=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_GROUP),
    true
), "\n";

echo 'UNIQUE_ALONE=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(PDO::FETCH_UNIQUE),
    true
), "\n";

echo 'FUNC_GROUP=', var_export(
    $pdo->query('SELECT g,n FROM t')->fetchAll(
        PDO::FETCH_FUNC | PDO::FETCH_GROUP,
        static fn (string $n): string => strtoupper($n)
    ),
    true
), "\n";
?>
--EXPECT--
ASSOC_GROUP=array (
  'x' => array (
    0 => array (
      'n' => 'a',
    ),
    1 => array (
      'n' => 'b',
    ),
  ),
  'y' => array (
    0 => array (
      'n' => 'c',
    ),
  ),
)
BOTH_GROUP=array (
  'x' => array (
    0 => array (
      'n' => 'a',
      1 => 'a',
    ),
    1 => array (
      'n' => 'b',
      1 => 'b',
    ),
  ),
  'y' => array (
    0 => array (
      'n' => 'c',
      1 => 'c',
    ),
  ),
)
NUM_GROUP=array (
  'x' => array (
    0 => array (
      0 => 'a',
    ),
    1 => array (
      0 => 'b',
    ),
  ),
  'y' => array (
    0 => array (
      0 => 'c',
    ),
  ),
)
COLUMN_GROUP=array (
  'x' => array (
    0 => 'a',
    1 => 'b',
  ),
  'y' => array (
    0 => 'c',
  ),
)
ASSOC_UNIQUE=array (
  'x' => array (
    'n' => 'b',
  ),
  'y' => array (
    'n' => 'c',
  ),
)
COLUMN_UNIQUE=array (
  'x' => 'b',
  'y' => 'c',
)
OBJ_GROUP=array (
  'x' => array (
    0 => (object) array(
       'n' => 'a',
    ),
    1 => (object) array(
       'n' => 'b',
    ),
  ),
  'y' => array (
    0 => (object) array(
       'n' => 'c',
    ),
  ),
)
ASSOC_GROUP_3COL=array (
  'x' => array (
    0 => array (
      'n' => 'a',
      'm' => 1,
    ),
    1 => array (
      'n' => 'b',
      'm' => 2,
    ),
  ),
  'y' => array (
    0 => array (
      'n' => 'c',
      'm' => 3,
    ),
  ),
)
GROUP_ALONE=array (
  'x' => array (
    0 => array (
      'n' => 'a',
      1 => 'a',
    ),
    1 => array (
      'n' => 'b',
      1 => 'b',
    ),
  ),
  'y' => array (
    0 => array (
      'n' => 'c',
      1 => 'c',
    ),
  ),
)
UNIQUE_ALONE=array (
  'x' => array (
    'n' => 'b',
    1 => 'b',
  ),
  'y' => array (
    'n' => 'c',
    1 => 'c',
  ),
)
FUNC_GROUP=array (
  'x' => array (
    0 => 'A',
    1 => 'B',
  ),
  'y' => array (
    0 => 'C',
  ),
)
