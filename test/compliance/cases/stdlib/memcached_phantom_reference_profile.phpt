--TEST--
stdlib memcached phantom withhold on reference profile (#6099)
--FILE--
<?php
declare(strict_types=1);

echo class_exists('Memcached', false) ? '1' : '0';
echo extension_loaded('memcached') ? '1' : '0';
echo "\n";
?>
--EXPECT--
00
