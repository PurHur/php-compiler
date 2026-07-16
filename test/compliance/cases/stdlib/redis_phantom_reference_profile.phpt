--TEST--
stdlib redis phantom withhold on reference profile (#6098)
--FILE--
<?php
declare(strict_types=1);

echo class_exists('Redis', false) ? '1' : '0';
echo class_exists('RedisException', false) ? '1' : '0';
echo extension_loaded('redis') ? '1' : '0';
echo "\n";
?>
--EXPECT--
000
