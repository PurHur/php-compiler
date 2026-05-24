--TEST--
Stdlib: trait_exists() with ::class and dynamic string (JIT, #1371)
--FILE--
<?php
trait BoxT {}
$name = 'BoxT';
echo trait_exists(BoxT::class) ? '1' : '0';
echo trait_exists($name) ? '1' : '0';
echo trait_exists('Missing') ? '1' : '0';
echo trait_exists('boxt') ? '1' : '0';
echo "\n";
--EXPECT--
1101

