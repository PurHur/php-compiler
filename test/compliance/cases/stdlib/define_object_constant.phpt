--TEST--
stdlib define() object constant — dynamic properties preserved (#17676, ext/standard/basic_functions.c)
--FILE--
<?php
define('MY_OBJ', (object) ['x' => 1, 'y' => 2]);
echo MY_OBJ->x, "\n";
echo MY_OBJ->y, "\n";
echo json_encode(MY_OBJ), "\n";
var_export(get_object_vars(MY_OBJ));
?>
--EXPECT--
1
2
{"x":1,"y":2}
array (
  'x' => 1,
  'y' => 2,
)
