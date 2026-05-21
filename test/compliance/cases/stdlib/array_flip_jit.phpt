--TEST--
stdlib array_flip() JIT for string-keyed associative arrays
--FILE--
<?php
$a = array('a' => 1, 'b' => 2);
$f = array_flip($a);
echo $f[1], "\n";
echo $f[2], "\n";
--EXPECT--
a
b
