<?php
/**
 * Repro #24660 — get_defined_vars() must omit unassigned compile-time locals (Zend symbol table).
 */
$foo = 1;
$keys = array_keys(get_defined_vars());
sort($keys);
echo implode(',', $keys), "\n";
$bar = null;
$keys2 = array_keys(get_defined_vars());
sort($keys2);
echo implode(',', $keys2), "\n";
