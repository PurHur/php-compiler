--TEST--
Pdo\Sqlite::__construct initializes like PDO::connect (#21096, #22600)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
if (!class_exists('Pdo\\Sqlite')) die('skip no Pdo\\Sqlite');
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) die('skip no sqlite driver');
?>
--FILE--
<?php
declare(strict_types=1);

$via = PDO::connect('sqlite::memory:');
echo get_class($via), "\n";
$via->exec('CREATE TABLE t(a)');
echo $via->query('SELECT 1')->fetchColumn(), "\n";

$d = new Pdo\Sqlite('sqlite::memory:');
echo get_class($d), "\n";
$d->exec('CREATE TABLE u(a)');
echo $d->query('SELECT 2')->fetchColumn(), "\n";

$d->sqliteCreateFunction('dbl', static function (int $n): int {
    return $n * 2;
}, 1);
echo $d->query('SELECT dbl(21)')->fetchColumn(), "\n";

$rc = new ReflectionClass(Pdo\Sqlite::class);
$ctor = $rc->getConstructor();
echo null !== $ctor ? $ctor->getDeclaringClass()->getName() : 'no-ctor', "\n";
?>
--EXPECT--
Pdo\Sqlite
1
Pdo\Sqlite
2
42
PDO
