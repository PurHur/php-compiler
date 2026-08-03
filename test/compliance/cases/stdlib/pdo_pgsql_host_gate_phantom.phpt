--TEST--
pdo_pgsql withheld without host module / ENABLE (#26140)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
if (extension_loaded('pdo_pgsql')) die('skip host pdo_pgsql loaded');
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
ext=0
has_pgsql_driver=0
open=no_driver
