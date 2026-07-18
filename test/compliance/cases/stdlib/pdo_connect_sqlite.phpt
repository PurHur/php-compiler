--TEST--
PDO::connect() + Pdo\Sqlite factory (#20529)
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) die('skip no sqlite driver');
?>
--FILE--
<?php
declare(strict_types=1);

$p = PDO::connect('sqlite::memory:');
echo get_class($p), "\n";
echo ($p instanceof PDO) ? 'isa-pdo:Y' : 'isa-pdo:N', "\n";
echo ($p instanceof Pdo\Sqlite) ? 'isa-sqlite:Y' : 'isa-sqlite:N', "\n";
echo $p->query('SELECT 1')->fetchColumn(), "\n";

$legacy = new PDO('sqlite::memory:');
echo get_class($legacy), "\n";
?>
--EXPECT--
Pdo\Sqlite
isa-pdo:Y
isa-sqlite:Y
1
PDO
