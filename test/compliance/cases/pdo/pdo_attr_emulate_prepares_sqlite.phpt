--TEST--
PDO ATTR_EMULATE_PREPARES on sqlite is unsupported (IM001) (#20413)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) die('skip no sqlite driver');
?>
--FILE--
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo 'set_errmode=', var_export($pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION), true), "\n";
echo 'get_errmode=', $pdo->getAttribute(PDO::ATTR_ERRMODE), "\n";
echo 'driver=', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), "\n";

echo 'set_emulate=', var_export($pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true), true), "\n";

try {
    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    echo "get_emulate=OK\n";
} catch (PDOException $e) {
    echo (str_contains($e->getMessage(), 'IM001') ? 'get_emulate_im001=Y' : 'get_emulate_im001=N'), "\n";
    echo (str_contains($e->getMessage(), 'does not support that attribute') ? 'get_emulate_msg=Y' : 'get_emulate_msg=N'), "\n";
}
?>
--EXPECT--
set_errmode=true
get_errmode=2
driver=sqlite
set_emulate=false
get_emulate_im001=Y
get_emulate_msg=Y
