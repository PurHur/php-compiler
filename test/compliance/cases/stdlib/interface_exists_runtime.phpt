--TEST--
Stdlib: interface_exists() with ::class and dynamic string (VM, #1371)
--FILE--
<?php
interface BoxI {}
$name = 'BoxI';
echo interface_exists(BoxI::class) ? '1' : '0';
echo interface_exists($name) ? '1' : '0';
echo interface_exists('Missing') ? '1' : '0';
echo interface_exists('boxi') ? '1' : '0';
echo "\n";
--EXPECT--
1101

