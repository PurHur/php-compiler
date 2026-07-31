--TEST--
Stdlib: PDO::connect() on PROFILE=8.4 (#22600, #20529)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
PHP_COMPILER_PROFILE=8.4
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
echo ($p instanceof Pdo\Sqlite) ? 'isa-sqlite:Y' : 'isa-sqlite:N', "\n";
--EXPECT--
Pdo\Sqlite
isa-sqlite:Y
