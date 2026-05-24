--TEST--
Stdlib: class_exists() with ::class and dynamic string (JIT, #1056)
--FILE--
<?php
class Box {}
$name = 'Box';
echo class_exists(Box::class) ? '1' : '0';
echo class_exists($name) ? '1' : '0';
echo class_exists('Missing') ? '1' : '0';
echo class_exists('box') ? '1' : '0';
echo "\n";
--EXPECT--
1101
