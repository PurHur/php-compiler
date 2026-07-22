<?php
// #21984 — func_get_args()/func_get_arg() reflect current parameter values (php-src-strict)
function f($a) {
    $a = 99;
    return json_encode(func_get_args());
}
echo f(1), "\n";

function g($a, $b = 2) {
    $a = 99;
    return json_encode(func_get_args());
}
echo g(1), "\n";
echo g(1, 3), "\n";

function h($a) {
    $args = func_get_args();
    $a = 99;
    return json_encode([$args, func_get_args()]);
}
echo h(1), "\n";

function p($a) {
    $a = 99;
    return (string) func_get_arg(0);
}
echo p(1), "\n";

class C {
    public function m($a) {
        $a = 99;
        return json_encode(func_get_args());
    }
    public static function s($a) {
        $a = 99;
        return json_encode(func_get_args());
    }
}
echo (new C())->m(1), "\n";
echo C::s(1), "\n";
