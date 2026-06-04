<?php
$a = ['x' => ['y' => 'hi'], 'z' => 'lo'];
array_walk_recursive($a, 'strtoupper');
echo $a['x']['y'], '|', $a['z'], "\n";
