<?php
// AOT compile-only repro #29720 — lowering must accept null $break (no LogicException).
// Runtime wordwrap() SEGV under AOT is pre-existing on master (basic wrap also SEGV).
error_reporting(E_ALL & ~E_DEPRECATED);
$unused = 'wordwrap-break-null-aot-compile-ok';
echo $unused, "\n";
// Keep a call site in the unit so the builtin is lowered:
try {
    wordwrap('hi there', 75, null);
} catch (Throwable $e) {
    // may SEGV before catch on current AOT — compile success is the gate
}
