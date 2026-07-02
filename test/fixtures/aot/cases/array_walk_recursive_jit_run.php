<?php
$a = ['x' => ['y' => ' hi '], 'z' => ' lo '];
array_walk_recursive($a, 'trim');
echo $a['x']['y'], '|', $a['z'], "\n";
