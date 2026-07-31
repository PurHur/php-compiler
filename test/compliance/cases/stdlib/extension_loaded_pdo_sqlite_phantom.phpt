--TEST--
stdlib extension_loaded('pdo_sqlite') false without host pdo_sqlite (#24523)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('pdo_sqlite'), "\n";
echo 'in_list=', (int) in_array('pdo_sqlite', get_loaded_extensions(), true), "\n";
echo 'drivers=', implode(',', PDO::getAvailableDrivers()), "\n";
try {
    new PDO('sqlite::memory:');
    echo "open=ok\n";
} catch (PDOException $e) {
    echo 'open=', $e->getMessage(), "\n";
}
?>
--EXPECT--
loaded=0
in_list=0
drivers=
open=could not find driver
