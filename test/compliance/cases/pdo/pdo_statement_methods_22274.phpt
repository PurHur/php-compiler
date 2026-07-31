--TEST--
stdlib PDOStatement bindColumn/nextRowset/debugDumpParams/attrs/getIterator (#22274)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
foreach (['bindColumn', 'nextRowset', 'debugDumpParams', 'getAttribute', 'setAttribute', 'getIterator', 'bindParam'] as $m) {
    echo $m, '=', method_exists('PDOStatement', $m) ? 'Y' : 'N', "\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$pdo->exec('CREATE TABLE t(a INT, b TEXT)');
$pdo->exec('INSERT INTO t VALUES(1, "x")');
$st = $pdo->query('SELECT a, b FROM t');
$a = null;
$b = null;
echo 'bindColumn1=', $st->bindColumn(1, $a) ? '1' : '0', "\n";
echo 'bindColumnB=', $st->bindColumn('b', $b) ? '1' : '0', "\n";
echo 'fetchBound=', $st->fetch(PDO::FETCH_BOUND) ? '1' : '0', "\n";
echo 'a=', var_export($a, true), ' b=', var_export($b, true), "\n";

$st2 = $pdo->query('SELECT 1 AS n');
echo 'emulate=', var_export($st2->getAttribute(PDO::ATTR_EMULATE_PREPARES), true), "\n";
echo 'setAttr=', var_export($st2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING), true), "\n";
echo 'nextRowset=', var_export($st2->nextRowset(), true), "\n";
$it = $st2->getIterator();
echo 'iter=', $it instanceof InternalIterator ? 'InternalIterator' : get_class($it), "\n";

$st3 = $pdo->prepare('SELECT ?');
$st3->bindValue(1, 42);
ob_start();
$st3->debugDumpParams();
$dump = ob_get_clean();
echo 'dump_sql=', (false !== strpos($dump, 'SQL:')) ? '1' : '0', "\n";
echo 'dump_params=', (false !== strpos($dump, 'Params: 1')) ? '1' : '0', "\n";
?>
--EXPECT--
bindColumn=Y
nextRowset=Y
debugDumpParams=Y
getAttribute=Y
setAttribute=Y
getIterator=Y
bindParam=Y
bindColumn1=1
bindColumnB=1
fetchBound=1
a=1 b='x'
emulate=false
setAttr=false
nextRowset=false
iter=InternalIterator
dump_sql=1
dump_params=1
