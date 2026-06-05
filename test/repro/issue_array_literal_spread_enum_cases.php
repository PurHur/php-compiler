<?php
/**
 * Repro for #5569 — array literal spread must preserve enum case objects, not backing scalars.
 * Zend reference: Zend/zend_execute.c (ZEND_INIT_ARRAY, spread).
 */
enum E: int {
    case A = 1;
    case B = 2;
    case C = 3;
}

$src = [E::A, E::B];
$out = [...$src];
var_export($out);
echo "\n";

$rest = [E::B, E::C];
$mixed = [E::A, ...$rest];
var_export($mixed);
echo "\n";
