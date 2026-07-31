--TEST--
stdlib PDO lastInsertId/quote/txn/errorInfo (#19861, ext/pdo/pdo.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t(id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
$pdo->exec('INSERT INTO t(v) VALUES("a")');
echo 'lastInsertId=', $pdo->lastInsertId(), "\n";
echo 'quote=', $pdo->quote("O'Reilly"), "\n";
echo 'begin=', $pdo->beginTransaction() ? '1' : '0', "\n";
$pdo->exec('INSERT INTO t(v) VALUES("b")');
echo 'inTransaction=', $pdo->inTransaction() ? '1' : '0', "\n";
echo 'commit=', $pdo->commit() ? '1' : '0', "\n";
echo 'count=', $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(), "\n";
$pdo->beginTransaction();
$pdo->exec('INSERT INTO t(v) VALUES("c")');
echo 'rollBack=', $pdo->rollBack() ? '1' : '0', "\n";
echo 'count2=', $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(), "\n";
echo 'errorCode=', $pdo->errorCode(), "\n";
$ei = $pdo->errorInfo();
echo 'errorInfo0=', $ei[0], "\n";
echo 'has_methods=',
    (method_exists($pdo, 'lastInsertId') && method_exists($pdo, 'quote')
        && method_exists($pdo, 'beginTransaction') && method_exists($pdo, 'commit')
        && method_exists($pdo, 'rollBack') && method_exists($pdo, 'inTransaction')
        && method_exists($pdo, 'errorCode') && method_exists($pdo, 'errorInfo'))
        ? 'yes' : 'no',
    "\n";
?>
--EXPECT--
lastInsertId=1
quote='O''Reilly'
begin=1
inTransaction=1
commit=1
count=2
rollBack=1
count2=2
errorCode=00000
errorInfo0=00000
has_methods=yes
