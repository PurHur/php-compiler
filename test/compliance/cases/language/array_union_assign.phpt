--TEST--
Language: array union assign += (Zend zend_assign_op_overloaded parity, #3690)
--FILE--
<?php
$a = ['x' => 1];
$a += ['y' => 2, 'x' => 9];
echo $a['x'], "\n";
echo $a['y'], "\n";
echo isset($a['x']) ? 1 : 0, "\n";
--EXPECT--
1
2
1
