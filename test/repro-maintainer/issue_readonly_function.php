<?php
/**
 * Issue #6485 repro — readonly() on dynamic stdClass objects.
 */
var_export(function_exists('readonly'));
echo "\n";
$o = (object)['x' => 1];
readonly($o);
try {
    $o->x = 2;
    echo "mutated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    readonly($o);
} catch (Error $e) {
    echo 'reapply: ', $e->getMessage(), "\n";
}
