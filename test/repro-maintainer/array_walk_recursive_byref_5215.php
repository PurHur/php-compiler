<?php

declare(strict_types=1);

$b = ['x' => [1, 2]];
array_walk_recursive($b, function (&$v) {
    $v++;
});
echo json_encode($b), "\n";

$c = [0 => [1 => [2 => 3]]];
array_walk_recursive($c, function (&$v) {
    $v = 9;
});
echo json_encode($c), "\n";
