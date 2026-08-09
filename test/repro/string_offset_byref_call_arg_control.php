<?php
// Control: by-ref array dim must still work (not string offset).
error_reporting(E_ALL);
function f(&$x){ $x = 5; }
function g(){ return [1]; }
$a = [1, 2];
f($a[0]);
echo "arr=", $a[0], "\n";
f(g()[0]);
echo "func_ok\n";
