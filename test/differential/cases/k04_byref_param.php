<?php
// FAILS ON AOT — #24162. Expected "9", AOT prints nothing at all.
//
// Bounding evidence: the failure is not "the caller's variable was not updated" — that would print
// the OLD value. Nothing is printed, so the echo itself produces no output. The by-value control
// below the same shape (function, typed param, echo of the result) is correct on AOT:
//     function f(int $x): int { return $x + 1; }  $n = 5; echo f($n), ' ', $n;   // ok, "6 5"
// It is the & that breaks it. Deterministic: 0/3 runs matched.
function addOne(int &$x): void { $x = 9; }
$n = 1;
addOne($n);
echo $n, "\n";
