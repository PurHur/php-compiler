<?php
// array_map(null, …) multi-array zip under AOT (#34978 / re-#16225).
var_export(array_map(null, [1, 2], [3, 4]));
echo "\n";
$z = array_map(null, [1, 2], ['a', 'b']);
echo $z[0][0], $z[0][1], '|', $z[1][0], $z[1][1], "\n";
