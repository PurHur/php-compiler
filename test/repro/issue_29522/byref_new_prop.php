<?php
// #29522 — by-ref arg to (new …)->prop must compile-fatal (Zend write context).
function f(&$x){ $x = 5; }
f((new stdClass)->x);
echo "new_ok\n";
