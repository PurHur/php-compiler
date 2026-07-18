--TEST--
ext/sqlite3 SQLite3::openBlob BLOB stream (#20599, php-src sqlite3.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, b BLOB); INSERT INTO t(b) VALUES (X\'0102\');');
echo 'openBlob=', method_exists($db, 'openBlob') ? '1' : '0', "\n";
$h = $db->openBlob('t', 'b', 1, 'main', SQLITE3_OPEN_READONLY);
echo 'type=', get_resource_type($h), "\n";
echo 'hex=', bin2hex(stream_get_contents($h)), "\n";
fclose($h);
$w = $db->openBlob('t', 'b', 1, 'main', SQLITE3_OPEN_READWRITE);
fwrite($w, "\x0a\x0b");
fseek($w, 0);
echo 'whex=', bin2hex(stream_get_contents($w)), "\n";
fclose($w);
$bad = $db->openBlob('nope', 'b', 1);
echo 'bad=', ($bad === false) ? '0' : '1', "\n";
?>
--EXPECT--
openBlob=1
type=stream
hex=0102
whex=0a0b
bad=0
