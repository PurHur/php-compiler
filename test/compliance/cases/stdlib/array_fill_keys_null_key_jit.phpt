--TEST--
stdlib array_fill_keys() — null key coerces to empty string (JIT)
--FILE--
<?php
$a = array_fill_keys([null], 'x');
echo $a[''], "\n";
--EXPECT--
x
