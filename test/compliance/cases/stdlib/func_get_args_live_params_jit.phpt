--TEST--
stdlib func_get_args()/func_get_arg() live params JIT (issue #21984)
--FILE--
<?php
function f($a) {
    $a = 99;
    echo json_encode(func_get_args()), "\n";
}
f(1);

function g($a, $b = 2) {
    $a = 99;
    echo json_encode(func_get_args()), "\n";
}
g(1);
g(1, 3);

function p($a) {
    $a = 99;
    echo func_get_arg(0), "\n";
}
p(1);

class C {
    public static function s($a) {
        $a = 99;
        echo json_encode(func_get_args()), "\n";
    }
}
C::s(1);
?>
--EXPECT--
[99]
[99]
[99,3]
99
[99]
