<?php
/**
 * #33728 — AOT array_walk / array_walk_recursive string callbacks (user fn + builtin).
 *
 * php-src: ext/standard/array.c — php_array_walk / php_array_walk_recursive
 */
function bump(&$v, $k)
{
    $v = ((int) $v) + 10;
}

$a = [1, 2];
array_walk($a, 'bump');
echo 'user:', json_encode($a), PHP_EOL;

$b = [1, 2];
array_walk($b, 'intval');
echo 'intval:', json_encode($b), PHP_EOL;

$c = ['x' => 5, 'y' => 7];
array_walk($c, 'bump');
echo 'assoc:', json_encode($c), PHP_EOL;

$d = [1, [2, 3], 4];
array_walk_recursive($d, 'bump');
echo 'rec:', json_encode($d), PHP_EOL;
