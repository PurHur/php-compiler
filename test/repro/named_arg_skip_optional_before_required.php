<?php
/** Repro #25728 — named skip of optional-before-required → ArgumentCountError */
function f($a = 1, $b) {
    echo "$a-$b\n";
}
try {
    f(b: 2);
    echo "FAIL: expected ArgumentCountError\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage() . "\n";
}
// Controls
f(a: 9, b: 2);
f(1, 2);
