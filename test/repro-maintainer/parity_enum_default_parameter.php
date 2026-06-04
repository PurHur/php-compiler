<?php
enum E: int {
    case A = 1;
    case B = 2;
}
function f(E $e = E::A) {
    return $e;
}
var_export([f(), f(E::B)]);
echo (f() === E::A) ? "same\n" : "diff\n";
