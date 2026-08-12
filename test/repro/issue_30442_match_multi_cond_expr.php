<?php
/**
 * Issue #30442: match expression comma-separated expression-conditions
 * must evaluate all conditions, not just the first.
 */

$x = 10;
$r1 = match(true) {
    $x === 5, $x === 10 => "five or ten",
    default => "other",
};
assert($r1 === "five or ten", "Repro 1 failed: got '$r1'");

$val = "hello";
$r2 = match($val) {
    strtoupper("hello"), strtolower("HELLO") => "matched",
    default => "no match",
};
assert($r2 === "matched", "Repro 2 failed: got '$r2'");

// Literal comma conditions must still work
$x = 2;
$r3 = match($x) {
    1, 2 => "one or two",
    default => "other",
};
assert($r3 === "one or two", "Repro 3 failed: got '$r3'");

// First condition in arm still matches
$x = 5;
$r4 = match(true) {
    $x === 5, $x === 10 => "five or ten",
    default => "other",
};
assert($r4 === "five or ten", "Repro 4 failed: got '$r4'");

echo "OK\n";
