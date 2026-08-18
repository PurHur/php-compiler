--TEST--
Language: @$undef inside closure/function still populates error_get_last (#32041)
--FILE--
<?php
error_reporting(E_ALL);

error_clear_last();
$fn = function () {
    @$undef_closure;
    $e = error_get_last();
    echo 'c-type=', $e['type'] ?? 'none', "\n";
    echo 'c-msg=', $e['message'] ?? 'none', "\n";
};
$fn();

error_clear_last();
function f_32041() {
    @$undef_func;
    $e = error_get_last();
    echo 'f-type=', $e['type'] ?? 'none', "\n";
    echo 'f-msg=', $e['message'] ?? 'none', "\n";
}
f_32041();

error_clear_last();
function g_32041() {
    $x = 1;
    @$x;
    $e = error_get_last();
    echo 'g-msg=', $e['message'] ?? 'none', "\n";
}
g_32041();
--EXPECT--
c-type=2
c-msg=Undefined variable $undef_closure
f-type=2
f-msg=Undefined variable $undef_func
g-msg=none
