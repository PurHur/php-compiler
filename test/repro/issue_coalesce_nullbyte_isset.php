<?php
// Coalesce on null-byte key + isset: bag looks populated then compound isset fails (re-#24282)
echo "has_debug=", var_export(method_exists("MultipleIterator", "__debugInfo"), true), "\n";
$mi = new MultipleIterator();
$mi->attachIterator(new ArrayIterator([1, 2]), "x");
$dbg = $mi->__debugInfo();
echo "keys=", implode(",", array_keys($dbg)), "\n";
$bag = $dbg["\0SplObjectStorage\0storage"] ?? null;
echo "rows=", is_array($bag) ? count($bag) : "null", "\n";
if (is_array($bag) && isset($bag[0]["inf"])) {
    echo "inf0=", var_export($bag[0]["inf"], true), "\n";
} else {
    echo "compound_isset_failed type=", gettype($bag), "\n";
}
// Control without ??
$bag2 = $dbg["\0SplObjectStorage\0storage"];
if (is_array($bag2) && isset($bag2[0]["inf"])) {
    echo "no_coalesce_inf0=", var_export($bag2[0]["inf"], true), "\n";
}
