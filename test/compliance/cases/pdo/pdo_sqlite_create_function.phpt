--TEST--
stdlib PDO::sqliteCreateFunction scalar UDF (#19863, ext/pdo_sqlite)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
echo 'has_fn=', method_exists($pdo, 'sqliteCreateFunction') ? 'yes' : 'no', "\n";
echo 'has_agg=', method_exists($pdo, 'sqliteCreateAggregate') ? 'yes' : 'no', "\n";
echo 'has_col=', method_exists($pdo, 'sqliteCreateCollation') ? 'yes' : 'no', "\n";
echo 'create=', $pdo->sqliteCreateFunction('dbl', static function ($x) { return $x * 2; }, 1) ? '1' : '0', "\n";
echo 'dbl=', $pdo->query('SELECT dbl(21)')->fetchColumn(), "\n";
?>
--EXPECT--
has_fn=yes
has_agg=yes
has_col=yes
create=1
dbl=42
