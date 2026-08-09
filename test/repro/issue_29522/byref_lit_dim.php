<?php
// #29522 — by-ref arg to literal array dim must compile-fatal (Zend write context).
function f(&$x){ $x = 5; }
f([1,2][0]);
echo "lit_ok\n";
