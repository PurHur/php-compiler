--TEST--
ext/sqlite3 SQLite3Stmt bindParam/getSQL/readOnly + named binds (#19854, ext/sqlite3/sqlite3.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(v TEXT)');
$st = $db->prepare('INSERT INTO t VALUES (:v)');
echo 'bindParam=', method_exists($st, 'bindParam') ? '1' : '0', "\n";
echo 'getSQL=', method_exists($st, 'getSQL') ? '1' : '0', "\n";
echo 'readOnly=', method_exists($st, 'readOnly') ? '1' : '0', "\n";
echo 'sql=', $st->getSQL(), "\n";
echo 'readOnly_insert=', $st->readOnly() ? '1' : '0', "\n";
$v = 'hello';
echo 'bp=', $st->bindParam(':v', $v) ? '1' : '0', "\n";
$st->execute();
$v = 'world';
$st->execute();
$sel = $db->prepare('SELECT v FROM t ORDER BY rowid');
echo 'readOnly_select=', $sel->readOnly() ? '1' : '0', "\n";
$res = $sel->execute();
echo 'colType_before=', var_export($res->columnType(0), true), "\n";
while ($row = $res->fetchArray(SQLITE3_NUM)) {
    echo 'row=', $row[0], ' colType=', $res->columnType(0), "\n";
}
$named = $db->prepare('INSERT INTO t VALUES (:name)');
echo 'bv_named=', $named->bindValue(':name', 'named') ? '1' : '0', "\n";
$named->execute();
$q = $db->query('SELECT COUNT(*) AS c FROM t');
$c = $q->fetchArray(SQLITE3_ASSOC);
echo 'count=', $c['c'], "\n";
?>
--EXPECT--
bindParam=1
getSQL=1
readOnly=1
sql=INSERT INTO t VALUES (:v)
readOnly_insert=0
bp=1
readOnly_select=1
colType_before=false
row=hello colType=3
row=world colType=3
bv_named=1
count=3
