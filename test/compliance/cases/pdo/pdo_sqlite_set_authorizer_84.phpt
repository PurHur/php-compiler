--TEST--
ext Pdo\Sqlite::setAuthorizer absent on PROFILE=8.4 (#27676)
--ENV--
PHP_COMPILER_PROFILE=8.4
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
var_export(method_exists(Pdo\Sqlite::class, 'setAuthorizer'));
echo "\n";
?>
--EXPECT--
false
