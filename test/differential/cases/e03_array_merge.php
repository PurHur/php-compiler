<?php
// Exercise array_merge; scalar keys — print_r(array) needs Runtime->vm in thin AOT (#23540).
$b = ['k' => 1];
$m1 = array_merge(['a' => 0], $b);
$m2 = array_merge($b, ['z' => 9]);
echo $m1['a'], '|', $m1['k'], "\n";
echo $m2['k'], '|', $m2['z'];
