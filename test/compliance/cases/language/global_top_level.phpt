--TEST--
Top-level script variables share storage with global import (issue #3601)
--FILE--
<?php
$x = 1;
function f(): void {
    global $x;
    echo (string) $x;
    echo "\n";
}
f();
$x = 2;
function g(): void {
    global $x;
    echo (string) $x;
    echo "\n";
}
g();
--EXPECT--
1
2
