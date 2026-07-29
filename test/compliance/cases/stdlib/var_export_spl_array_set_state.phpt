--TEST--
var_export ArrayIterator/ArrayObject __set_state storage (#24447)
--FILE--
<?php
echo var_export(new ArrayIterator([0 => 1, 'x' => 2]), true), "\n";
echo var_export(new ArrayObject([0 => 1, 'x' => 2]), true), "\n";
--EXPECT--
\ArrayIterator::__set_state(array(
   0 => 1,
   'x' => 2,
))
\ArrayObject::__set_state(array(
   0 => 1,
   'x' => 2,
))
