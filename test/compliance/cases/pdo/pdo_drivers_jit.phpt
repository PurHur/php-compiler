--TEST--
stdlib pdo_drivers() === PDO::getAvailableDrivers JIT (#20239, ext/pdo/pdo.c)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
echo (int) function_exists('pdo_drivers'), "\n";
$a = pdo_drivers();
$b = PDO::getAvailableDrivers();
echo (int) ($a === $b), "\n";
echo in_array('sqlite', $a, true) ? "sqlite\n" : "no-sqlite\n";
?>
--EXPECT--
1
1
sqlite
