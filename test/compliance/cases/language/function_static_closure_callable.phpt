--TEST--
language: Closure in function-local static remains callable (issue #28039, Zend/zend_closures.c)
--FILE--
<?php
function f() {
    static $c = null;
    if ($c === null) {
        $c = function ($n) { return $n * 2; };
    }
    return $c(3);
}
echo f(), "\n", f(), "\n";

function g() {
    static $c = null;
    $c ??= function ($n) { return $n * 2; };
    return $c(3);
}
echo g(), "\n", g(), "\n";

$outer = function () {
    static $c = null;
    if ($c === null) {
        $c = function ($n) { return $n * 2; };
    }
    return $c(3);
};
echo $outer(), "\n", $outer(), "\n";

function h() {
    static $c = null;
    if ($c === null) {
        $c = function ($n) { return $n * 2; };
    }
    return is_callable($c) ? 'Y' : 'N';
}
echo h(), "\n", h(), "\n";
?>
--EXPECT--
6
6
6
6
6
6
Y
Y
