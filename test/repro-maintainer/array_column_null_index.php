<?php

$d = [
    ['id' => 1, 'n' => 'a'],
    ['id' => 2, 'n' => 'b'],
];
$names = array_column($d, 'n', null);
echo count($names), "\n";
echo $names[0], "\n";
echo $names[1], "\n";
