--TEST--
stdlib array_reverse() JIT
--FILE--
<?php
$b = array_reverse([1, 2, 3]);
echo $b[0], $b[2], "\n";
--EXPECT--
31
