--TEST--
stdlib array_splice() mixed assoc replacement preserves trailing numeric keys (#10784)
--FILE--
<?php
$a = ['x' => 1, 'y' => 2, 0 => 3];
array_splice($a, 1, 1, ['z' => 9]);
echo json_encode($a), "\n";

$b = ['x' => 1, 'y' => 2, 0 => 3, 1 => 4];
array_splice($b, 1, 1, ['z' => 9]);
echo json_encode($b), "\n";
--EXPECT--
{"x":1,"0":9,"1":3}
{"x":1,"0":9,"1":3,"2":4}
