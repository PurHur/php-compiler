<?php
// #19854 — SQLite3Stmt bindParam/getSQL/readOnly + Result::columnType under AOT
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
