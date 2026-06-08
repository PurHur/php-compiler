<?php
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 9, 'c' => 3];
echo json_encode(array_diff_key($a, $b)), "\n";
echo json_encode(array_intersect_key($a, $b)), "\n";
