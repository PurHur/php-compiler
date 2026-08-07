--TEST--
Pdo\Mysql / Pdo\Pgsql subclasses (#20548)
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
PHP_COMPILER_ENABLE_PDO_MYSQL=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'sqlite=', var_export(class_exists('Pdo\\Sqlite'), true), "\n";
echo 'mysql=', var_export(class_exists('Pdo\\Mysql'), true), "\n";
echo 'pgsql=', var_export(class_exists('Pdo\\Pgsql'), true), "\n";

echo 'mysql_isa=', var_export(is_subclass_of('Pdo\\Mysql', 'PDO'), true), "\n";
echo 'mysql_buf=', Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, "\n";
echo 'mysql_warn=', var_export(method_exists('Pdo\\Mysql', 'getWarningCount'), true), "\n";

echo 'pgsql_isa=', var_export(is_subclass_of('Pdo\\Pgsql', 'PDO'), true), "\n";
echo 'pgsql_dis=', Pdo\Pgsql::ATTR_DISABLE_PREPARES, "\n";
echo 'pgsql_idle=', Pdo\Pgsql::TRANSACTION_IDLE, "\n";
echo 'pgsql_esc=', var_export(method_exists('Pdo\\Pgsql', 'escapeIdentifier'), true), "\n";

try {
    PDO::connect('mysql:host=127.0.0.1;dbname=test');
    echo "mysql_connect=OK\n";
} catch (PDOException $e) {
    echo 'mysql_connect=', $e->getMessage(), "\n";
}

try {
    PDO::connect('pgsql:host=127.0.0.1;dbname=test');
    echo "pgsql_connect=OK\n";
} catch (PDOException $e) {
    echo 'pgsql_connect=', $e->getMessage(), "\n";
}

$drivers = PDO::getAvailableDrivers();
echo 'has_mysql_driver=', var_export(in_array('mysql', $drivers, true), true), "\n";
echo 'has_pgsql_driver=', var_export(in_array('pgsql', $drivers, true), true), "\n";
?>
--EXPECTF--
sqlite=%s
mysql=true
pgsql=true
mysql_isa=true
mysql_buf=1000
mysql_warn=true
pgsql_isa=true
pgsql_dis=1000
pgsql_idle=0
pgsql_esc=true
mysql_connect=could not find driver
pgsql_connect=could not find driver
has_mysql_driver=false
has_pgsql_driver=false
