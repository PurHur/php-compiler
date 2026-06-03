<?php

function rhs() {
    echo "rhs\n";
    return [1, 2, 3];
}

[$a, $b] = rhs();
var_dump($a, $b);

$list = ['x' => 10, 'y' => 20];
['y' => $yy, 'x' => $xx] = $list;
var_dump($xx, $yy);

$arr = [1, 2];
[$r0, &$r1] = $arr;
$r1 = 999;
var_dump($arr);

[[ $n0 ], $n1] = [[7], 8];
var_dump($n0, $n1);

