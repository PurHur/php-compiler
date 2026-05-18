--TEST--
stdlib array_key_exists()
--FILE--
<?php
$a = array(10, 20);
echo array_key_exists(0, $a) ? 'y' : 'n', "\n";
echo array_key_exists(1, $a) ? 'y' : 'n', "\n";
echo array_key_exists(2, $a) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
