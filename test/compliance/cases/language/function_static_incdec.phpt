--TEST--
function-local static ++/-- — PostInc/PreInc/PostDec/PreDec (#5222, #2286)
--FILE--
<?php
function f(): int {
    static $x = 0;
    $x++;
    return $x;
}
echo f(), f(), "\n";

function g(): int {
    static $n = 0;
    ++$n;
    return $n;
}
echo g(), g(), "\n";

function h(): int {
    static $m = 1;
    $m--;
    return $m;
}
echo h(), "\n";

$f = function (): int {
    static $n = 0;
    return ++$n;
};
echo $f(), $f(), "\n";
--EXPECT--
12
12
0
12
