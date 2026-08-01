--TEST--
stdlib rar phantom withhold on reference profile (#6237)
--FILE--
<?php
declare(strict_types=1);

echo class_exists('RarArchive', false) ? '1' : '0';
echo class_exists('RarEntry', false) ? '1' : '0';
echo class_exists('RarException', false) ? '1' : '0';
echo extension_loaded('rar') ? '1' : '0';
echo "\n";
?>
--EXPECT--
0000
