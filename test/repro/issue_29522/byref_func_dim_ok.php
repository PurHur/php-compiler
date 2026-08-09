<?php
// #29522 — by-ref arg to function-return dim remains allowed (Zend).
function f(&$x){ $x = 5; }
function g(){ return [1]; }
f(g()[0]);
echo "func_ok\n";
