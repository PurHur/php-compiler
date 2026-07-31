<?php
/**
 * Issue #26172 AOT smoke — usort runtime returns true.
 * Reflection metadata is VM/JIT; ksort/krsort thin-AOT currently fails module
 * verify (KeySortJitHelper arity vs call site) on master — out of scope here.
 */
$a = [3, 1, 2];
echo (int) usort($a, static fn ($x, $y) => $x <=> $y), "\n";
