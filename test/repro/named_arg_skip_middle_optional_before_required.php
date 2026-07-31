<?php
/** Repro #25728 — named skip of middle optional-before-required → ArgumentCountError */
function f($a, $b = 2, $c) {
    echo "$a-$b-$c\n";
}
try {
    f(a: 1, c: 9);
    echo "FAIL: expected ArgumentCountError\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}
// Truly optional middle still applies default
function g($a, $b = 2, $c = 3) {
    echo "$a-$b-$c\n";
}
g(a: 1, c: 9);
