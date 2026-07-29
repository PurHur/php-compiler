<?php
/**
 * Repro for #23966: array_splice() must detach by-ref aliases on removed elements.
 *
 * Zend: $b keeps the detached value (2) after the element is spliced out.
 * Bug: $b followed the shifted element (read 3 instead of 2).
 */

$errors = 0;

// Packed array: by-ref alias on removed element
$a = [1, 2, 3, 4];
$b =& $a[1];
array_splice($a, 1, 1);
if ($a !== [1, 3, 4]) {
    echo "FAIL: array shape wrong after splice: " . json_encode($a) . "\n";
    $errors++;
} else {
    echo "OK: a=[1,3,4]\n";
}
if ($b !== 2) {
    echo "FAIL: \$b=$b (expected 2 — detached value)\n";
    $errors++;
} else {
    echo "OK: b=2 (detached)\n";
}

// By-ref alias on element that survives (not removed)
$c = [10, 20, 30, 40];
$d =& $c[0];
array_splice($c, 1, 2);
if ($c !== [10, 40]) {
    echo "FAIL: c shape wrong: " . json_encode($c) . "\n";
    $errors++;
} else {
    echo "OK: c=[10,40]\n";
}
if ($d !== 10) {
    echo "FAIL: \$d=$d (expected 10)\n";
    $errors++;
} else {
    echo "OK: d=10 (prefix survived)\n";
}

exit($errors > 0 ? 1 : 0);
