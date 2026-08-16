--TEST--
Pdo\Mysql/Sqlite/Pgsql do not inherit cross-driver PDO_*_Ext methods (#21552)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
PHP_COMPILER_ENABLE_PDO_MYSQL=1
PHP_COMPILER_ENABLE_PDO_PGSQL=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'Pdo\\Mysql::getWarningCount=', method_exists('Pdo\\Mysql', 'getWarningCount') ? 'yes' : 'no', "\n";
echo 'Pdo\\Mysql::sqliteCreateFunction=', method_exists('Pdo\\Mysql', 'sqliteCreateFunction') ? 'yes' : 'no', "\n";
echo 'Pdo\\Mysql::sqliteCreateAggregate=', method_exists('Pdo\\Mysql', 'sqliteCreateAggregate') ? 'yes' : 'no', "\n";
echo 'Pdo\\Mysql::pgsqlCopyFromArray=', method_exists('Pdo\\Mysql', 'pgsqlCopyFromArray') ? 'yes' : 'no', "\n";
echo 'Pdo\\Mysql::copyFromArray=', method_exists('Pdo\\Mysql', 'copyFromArray') ? 'yes' : 'no', "\n";
echo 'Pdo\\Sqlite::sqliteCreateFunction=', method_exists('Pdo\\Sqlite', 'sqliteCreateFunction') ? 'yes' : 'no', "\n";
echo 'Pdo\\Sqlite::sqliteCreateAggregate=', method_exists('Pdo\\Sqlite', 'sqliteCreateAggregate') ? 'yes' : 'no', "\n";
echo 'Pdo\\Sqlite::sqliteCreateCollation=', method_exists('Pdo\\Sqlite', 'sqliteCreateCollation') ? 'yes' : 'no', "\n";
echo 'Pdo\\Sqlite::getWarningCount=', method_exists('Pdo\\Sqlite', 'getWarningCount') ? 'yes' : 'no', "\n";
echo 'Pdo\\Sqlite::pgsqlCopyFromArray=', method_exists('Pdo\\Sqlite', 'pgsqlCopyFromArray') ? 'yes' : 'no', "\n";
echo 'Pdo\\Sqlite::copyFromArray=', method_exists('Pdo\\Sqlite', 'copyFromArray') ? 'yes' : 'no', "\n";
echo 'Pdo\\Pgsql::copyFromArray=', method_exists('Pdo\\Pgsql', 'copyFromArray') ? 'yes' : 'no', "\n";
echo 'Pdo\\Pgsql::pgsqlCopyFromArray=', method_exists('Pdo\\Pgsql', 'pgsqlCopyFromArray') ? 'yes' : 'no', "\n";
echo 'Pdo\\Pgsql::sqliteCreateFunction=', method_exists('Pdo\\Pgsql', 'sqliteCreateFunction') ? 'yes' : 'no', "\n";
echo 'Pdo\\Pgsql::getWarningCount=', method_exists('Pdo\\Pgsql', 'getWarningCount') ? 'yes' : 'no', "\n";
echo 'PDO::sqliteCreateFunction=', method_exists('PDO', 'sqliteCreateFunction') ? 'yes' : 'no', "\n";
echo 'PDO::sqliteCreateCollation=', method_exists('PDO', 'sqliteCreateCollation') ? 'yes' : 'no', "\n";
echo 'PDO::pgsqlCopyFromArray=', method_exists('PDO', 'pgsqlCopyFromArray') ? 'yes' : 'no', "\n";
echo 'PDO::getWarningCount=', method_exists('PDO', 'getWarningCount') ? 'yes' : 'no', "\n";
?>
--EXPECT--
Pdo\Mysql::getWarningCount=yes
Pdo\Mysql::sqliteCreateFunction=no
Pdo\Mysql::sqliteCreateAggregate=no
Pdo\Mysql::pgsqlCopyFromArray=no
Pdo\Mysql::copyFromArray=no
Pdo\Sqlite::sqliteCreateFunction=yes
Pdo\Sqlite::sqliteCreateAggregate=yes
Pdo\Sqlite::sqliteCreateCollation=yes
Pdo\Sqlite::getWarningCount=no
Pdo\Sqlite::pgsqlCopyFromArray=no
Pdo\Sqlite::copyFromArray=no
Pdo\Pgsql::copyFromArray=yes
Pdo\Pgsql::pgsqlCopyFromArray=yes
Pdo\Pgsql::sqliteCreateFunction=no
Pdo\Pgsql::getWarningCount=no
PDO::sqliteCreateFunction=yes
PDO::sqliteCreateCollation=yes
PDO::pgsqlCopyFromArray=no
PDO::getWarningCount=no
