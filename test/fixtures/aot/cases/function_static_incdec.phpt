--TEST--
AOT: function-local static ++/-- — PostInc/PreInc/PostDec/PreDec (#5230, #5222)
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
--EXPECT--
12
12
0
