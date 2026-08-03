--TEST--
pdo_pgsql host/ENABLE gate + drivers + DSN (#26140)
--ENV--
PHP_COMPILER_ENABLE_PDO_PGSQL=1
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'ext=', (int) extension_loaded('pdo_pgsql'), "\n";
echo 'has_pgsql_driver=', (int) in_array('pgsql', PDO::getAvailableDrivers(), true), "\n";
try {
    new PDO('pgsql:host=127.0.0.1;dbname=none', 'u', 'p');
    echo "open=ok\n";
} catch (PDOException $e) {
    echo str_contains($e->getMessage(), 'could not find driver') ? "open=no_driver\n" : "open=connect_err\n";
}
?>
--EXPECT--
ext=1
has_pgsql_driver=1
open=connect_err
