<?php
// @differential-repeat: 10 array_walk_recursive must SEPARATE nested HT before by-ref leaf mutate (#36397)
$b = ['x' => ['y' => 1]];
$a = $b;
array_walk_recursive($a, function (&$v) {
    $v = $v + 1;
});
echo $b['x']['y'], '|', $a['x']['y'], "\n";
