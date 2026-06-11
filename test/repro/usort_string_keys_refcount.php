<?php

$a = ['x' => 'c', 'y' => 'a', 'z' => 'b'];
usort($a, 'strcmp');
var_export($a);
echo "\n";
