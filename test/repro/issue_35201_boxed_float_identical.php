<?php
/**
 * #35201 — AOT boxed float === native float (and match) must match Zend.
 *
 * Prior: identicalToNative defaulted TYPE_NATIVE_DOUBLE → false; boxed-float
 * === boxed-float skipped doubles in identicalValueToValueTyped.
 *
 *   php bin/vm.php test/repro/issue_35201_boxed_float_identical.php
 *   php bin/compile.php -o /tmp/feq.bin test/repro/issue_35201_boxed_float_identical.php && /tmp/feq.bin
 */
$x = 1.5;
var_dump($x === 1.5);
var_dump($x !== 1.5);
var_dump($x == 1.5);
var_dump($x === 1);
$a = 1.5;
$b = 1.5;
var_dump($a === $b);
echo match ($x) {
    1.5 => "ok",
    default => "no",
}, "\n";
var_dump(NAN === NAN);
$n = NAN;
var_dump($n === NAN);
