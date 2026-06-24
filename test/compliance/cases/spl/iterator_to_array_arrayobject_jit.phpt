--TEST--
iterator_to_array() JIT on ArrayObject (#11228)
--JIT--
--FILE--
<?php
$ao = iterator_to_array(new ArrayObject(['k' => 9]));
echo $ao['k'], "\n";
--EXPECT--
9
