<?php
// array_walk_recursive COW (#36397) — nested by-ref leaf must not alias source.
$b = ['x' => ['y' => 1]];
$a = $b;
array_walk_recursive($a, function (&$v) {
    $v = $v + 1;
});
echo $b['x']['y'], '|', $a['x']['y'], "\n";
