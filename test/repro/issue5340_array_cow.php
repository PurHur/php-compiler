<?php
$a = [1];
$c = $a;
$c[0] = 99;
var_dump($a, $c);

$a = [1];
$b = &$a;
$c = $a;
$c[0] = 99;
var_dump($a, $b, $c);
