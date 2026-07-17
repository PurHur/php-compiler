<?php
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(v TEXT); INSERT INTO t VALUES ("a"),("b");');
echo 'query=', method_exists($db, 'query') ? '1' : '0', "\n";
echo 'prepare=', method_exists($db, 'prepare') ? '1' : '0', "\n";
echo 'SQLite3Result=', class_exists('SQLite3Result') ? '1' : '0', "\n";
$res = $db->query('SELECT v FROM t ORDER BY rowid');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    echo $row['v'], "\n";
}
$st = $db->prepare('INSERT INTO t VALUES (?)');
$st->bindValue(1, 'c');
$r2 = $st->execute();
echo 'prep_ok=', ($r2 instanceof SQLite3Result) ? '1' : '0', "\n";
$db->exec('INSERT INTO t VALUES ("d")');
echo 'changes=', $db->changes(), "\n";
echo 'last=', $db->lastInsertRowID(), "\n";
echo 'esc=', SQLite3::escapeString("o'reilly"), "\n";
