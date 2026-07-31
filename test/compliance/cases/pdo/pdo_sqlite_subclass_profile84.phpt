--TEST--
Pdo\Sqlite present on PROFILE=8.4 (#22790)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) die('skip no sqlite driver');
?>
--FILE--
<?php
echo 'Pdo\\Sqlite=', class_exists('Pdo\\Sqlite') ? 'yes' : 'no', "\n";
?>
--EXPECT--
Pdo\Sqlite=yes
