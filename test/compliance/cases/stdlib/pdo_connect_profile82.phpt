--TEST--
Stdlib: PDO — no connect() on PROFILE=8.2 (#22600, ext/pdo/pdo_dbh.stub.php)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
PHP_COMPILER_PROFILE=8.2
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'method_exists=', method_exists(PDO::class, 'connect') ? '1' : '0', "\n";
try {
    PDO::connect('sqlite::memory:');
    echo "connect=OK\n";
} catch (Error $e) {
    echo 'connect=', $e->getMessage(), "\n";
}
--EXPECTF--
method_exists=0
connect=%sconnect%s
