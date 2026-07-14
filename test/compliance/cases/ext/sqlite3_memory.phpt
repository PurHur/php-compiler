--TEST--
ext/sqlite3 SQLite3 :memory: exec/querySingle (#3434, ext/sqlite3/sqlite3.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t (v TEXT)');
$db->exec("INSERT INTO t VALUES ('ok')");
echo $db->querySingle('SELECT v FROM t'), "\n";
$db->close();
echo "closed\n";
--EXPECT--
ok
closed
