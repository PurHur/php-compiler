<?php
// Ordinary PHP: foreach with key => value. Passes both backends.
$a = ['x' => 1, 'y' => 2, 'z' => 3];
foreach ($a as $k => $v) { echo $k, '=', $v, ' '; }
echo "\n";
