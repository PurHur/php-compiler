<?php

declare(strict_types=1);

$a = 'hello';
$b = ['x' => 'world'];
$r = mb_convert_variables('UTF-8', 'ISO-8859-1', $a, $b);
var_dump($r, $a, $b);
