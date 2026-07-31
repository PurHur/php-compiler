--TEST--
stdlib PDOStatement bindParam/errorInfo/setFetchMode/getColumnMeta (#19853, ext/pdo/pdo_stmt.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(id INTEGER, name TEXT)');
$st = $pdo->prepare('INSERT INTO t VALUES (?, ?)');
$id = 7;
$name = 'x';
echo 'bindParam=', $st->bindParam(1, $id) && $st->bindParam(2, $name) ? 'yes' : 'no', "\n";
$id = 8;
$name = 'y';
$st->execute();
$row = $pdo->query('SELECT id, name FROM t')->fetch(PDO::FETCH_ASSOC);
echo 'bound=', $row['id'], ':', $row['name'], "\n";
$sel = $pdo->query('SELECT id, name FROM t');
echo 'setFetchMode=', $sel->setFetchMode(PDO::FETCH_NUM) ? 'yes' : 'no', "\n";
$r = $sel->fetch();
echo 'fetch_num=', $r[0], ':', $r[1], "\n";
$metaSt = $pdo->query('SELECT id AS a FROM t');
$meta = $metaSt->getColumnMeta(0);
echo 'meta_name=', $meta['name'], "\n";
echo 'meta_native=', $meta['native_type'], "\n";
echo 'errorInfo0=', $metaSt->errorInfo()[0], "\n";
foreach (['bindParam', 'errorInfo', 'setFetchMode', 'getColumnMeta'] as $m) {
    echo $m, '=', method_exists($metaSt, $m) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
bindParam=yes
bound=8:y
setFetchMode=yes
fetch_num=8:y
meta_name=a
meta_native=integer
errorInfo0=00000
bindParam=yes
errorInfo=yes
setFetchMode=yes
getColumnMeta=yes
