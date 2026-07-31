--TEST--
AOT: pdo_drivers() returns sqlite when pdo_sqlite available (#20239)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
echo (int) function_exists('pdo_drivers'), "\n";
$a = pdo_drivers();
echo count($a), "\n";
echo ($a[0] ?? '') === 'sqlite' ? "sqlite\n" : "no-sqlite\n";
?>
--EXPECT--
1
1
sqlite
