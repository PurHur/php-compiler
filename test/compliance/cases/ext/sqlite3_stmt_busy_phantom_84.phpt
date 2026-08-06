--TEST--
ext/sqlite3 SQLite3Stmt::busy withheld on PROFILE=8.4 (#27594, php-src sqlite3.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'class=', class_exists('SQLite3Stmt') ? '1' : '0', "\n";
echo 'busy=', method_exists('SQLite3Stmt', 'busy') ? 'phantom' : 'ok', "\n";
echo 'explain=', method_exists('SQLite3Stmt', 'explain') ? 'phantom' : 'ok', "\n";
echo 'setExplain=', method_exists('SQLite3Stmt', 'setExplain') ? 'phantom' : 'ok', "\n";
echo 'fetchAll=', (class_exists('SQLite3Result') && method_exists('SQLite3Result', 'fetchAll')) ? 'phantom' : 'ok', "\n";
?>
--EXPECT--
class=1
busy=ok
explain=ok
setExplain=ok
fetchAll=ok
