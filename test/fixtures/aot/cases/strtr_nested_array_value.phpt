--TEST--
AOT strtr() unused nested array replace value no TypeError (#28978)
--FILE--
<?php
error_reporting(E_ALL);
echo strtr('hi', ['h' => 'H', 'u' => ['x']]), "\n";
echo strtr('hi', ['h' => 'H', 'i' => 'I']), "\n";
--EXPECT--
Hi
HI
