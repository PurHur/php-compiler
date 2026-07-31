--TEST--
Pdo\Sqlite withheld on PROFILE=8.2; present on 8.4 (#22790)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo 'Pdo\\Sqlite=', class_exists('Pdo\\Sqlite') ? 'yes' : 'no', "\n";
?>
--EXPECT--
Pdo\Sqlite=no
