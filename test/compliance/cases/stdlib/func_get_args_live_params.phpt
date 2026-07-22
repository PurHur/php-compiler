--TEST--
stdlib func_get_args()/func_get_arg() reflect current parameter values (issue #21984)
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

function h($a) {
    $args = func_get_args();
    $a = 99;
    echo json_encode([$args, func_get_args()]), "\n";
}
h(1);

function p($a) {
    $a = 99;
    echo func_get_arg(0), "\n";
}
p(1);

class C {
    public function m($a) {
        $a = 99;
        echo json_encode(func_get_args()), "\n";
    }
    public static function s($a) {
        $a = 99;
        echo json_encode(func_get_args()), "\n";
    }
}
(new C())->m(1);
C::s(1);
?>
--EXPECT--
[99]
[99]
[99,3]
[[1],[99]]
99
[99]
[99]
