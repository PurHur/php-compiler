<?php
// Issue #30164: property/method access on resource — Zend on-resource diagnostics

$f = fopen('php://memory', 'r');

// 1. Property read: Warning: Attempt to read property "p" on resource
echo "--- read ---\n";
$v = @$f->p; // suppress for output, test message below
echo var_export($v, true) . "\n";

// Test warning message
set_error_handler(function ($code, $msg) {
    echo "WARNING: $msg\n";
    return true;
});
$v = $f->p;
restore_error_handler();

// 2. Property inc/dec: Error: Attempt to increment/decrement property "p" on resource
echo "--- inc ---\n";
try {
    $f->p++;
} catch (\Error $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 3. Method call: Error: Call to a member function foo() on resource
echo "--- method ---\n";
try {
    $f->foo();
} catch (\Error $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

fclose($f);
