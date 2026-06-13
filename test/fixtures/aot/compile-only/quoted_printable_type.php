<?php
// Compile-only (#4596): quoted_printable_* strict string operand TypeError on AOT path.
foreach (['quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    try {
        $fn([]);
        echo "{$fn}: no throw\n";
    } catch (TypeError $e) {
        echo "{$fn}: ", $e->getMessage(), "\n";
    }
}
