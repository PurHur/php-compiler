<?php
$a = null;
var_dump($a ??= 5, $a);
$b = $a ??= 5;
var_dump($b);

$x = null;
$y = null;
var_dump($x ??= $y ??= 1, $x, $y);
