--TEST--
stdlib array_walk_recursive() closure callback (#3111)
--FILE--
<?php
$a = ['x' => ['y' => 1], 'z' => 2];
array_walk_recursive($a, function ($v, $k) {
    echo $k, ':', $v, ' ';
});
--EXPECT--
y:1 z:2 
