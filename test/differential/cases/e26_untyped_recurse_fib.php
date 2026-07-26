<?php
// Untyped naive fib with two combined self-calls (#23482) — AOT used to fatal on
// missing Context::functionScopeBindingVariable().
function f($a) { return $a < 2 ? $a : f($a - 1) + f($a - 2); }
echo f(5), "\n";
