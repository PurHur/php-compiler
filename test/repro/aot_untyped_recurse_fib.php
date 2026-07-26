<?php
// Issue #23482: AOT crash on untyped recursive dual self-call in ternary.
function f($a) { return $a < 2 ? $a : f($a - 1) + f($a - 2); }
echo f(5), "\n";
