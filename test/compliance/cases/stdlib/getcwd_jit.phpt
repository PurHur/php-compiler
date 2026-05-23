--TEST--
stdlib getcwd() JIT
--FILE--
<?php
$dir = getcwd();
echo is_string($dir) && strlen($dir) > 0 ? '1' : '0';
echo "\n";
--EXPECT--
1
