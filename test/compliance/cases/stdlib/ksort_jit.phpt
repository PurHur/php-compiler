--TEST--
stdlib ksort() JIT (packed lists; string keys: VM)
--FILE--
<?php
$b = array(1, 2, 3);
ksort($b);
echo $b[0], ',', $b[1], ',', $b[2], "\n";
--EXPECT--
1,2,3
