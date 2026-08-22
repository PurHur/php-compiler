<?php
/**
 * #33728 — AOT array_walk / array_walk_recursive string callbacks must compile and match Zend.
 */
$a = [1, 2];
function bump33728(&$v, $k)
{
    $v += 10;
}
array_walk($a, 'bump33728');
echo json_encode($a), PHP_EOL;

$b = [1, 2];
array_walk($b, 'intval');
echo json_encode($b), PHP_EOL;

$c = ['x' => [1, 2]];
function bump_rec33728(&$v, $k)
{
    if (!is_array($v)) {
        $v += 10;
    }
}
array_walk_recursive($c, 'bump_rec33728');
echo json_encode($c), PHP_EOL;
