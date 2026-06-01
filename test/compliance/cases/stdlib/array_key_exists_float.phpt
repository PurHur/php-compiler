--TEST--
stdlib array_key_exists() float key coercion (php-src parity)
--FILE--
<?php
$a = array(1.5 => 'v');
echo array_key_exists(1.5, $a) ? 'y' : 'n', "\n";
echo array_key_exists(1, $a) ? 'y' : 'n', "\n";
echo array_key_exists(1.0, $a) ? 'y' : 'n', "\n";
echo array_key_exists(2.5, $a) ? 'y' : 'n', "\n";

$b = array(0 => 'zero', 2 => 'two');
echo array_key_exists(2.0, $b) ? 'y' : 'n', "\n";
echo array_key_exists(2.9, $b) ? 'y' : 'n', "\n";
--EXPECT--
y
y
y
n
y
y
