--TEST--
list() destructuring from explode() (JIT)
--FILE--
<?php
list($a, $b) = explode(',', '1,2');
echo $a, $b, "\n";
--EXPECT--
12
