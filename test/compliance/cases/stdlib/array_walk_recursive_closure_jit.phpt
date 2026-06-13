--TEST--
stdlib array_walk_recursive() closure callback JIT/AOT (#4039)
--FILE--
<?php
$a = ['x' => ['y' => 1], 'z' => 2];
$ok = array_walk_recursive($a, function ($v, $k) {
    echo $k, ':', $v, ' ';
});
echo $ok ? "ok\n" : "fail\n";
--EXPECT--
y:1 z:2 ok
