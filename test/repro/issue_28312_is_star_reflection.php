<?php
/**
 * #28312 — is_* / is_callable Reflection mixed $value + bool returns (type.stub.php).
 * Also #30242 — is_callable &$callable_name untyped with default null.
 */
$fns = [
    'is_numeric', 'is_string', 'is_int', 'is_integer', 'is_float', 'is_double',
    'is_bool', 'is_null', 'is_array', 'is_object', 'is_resource', 'is_scalar', 'is_callable',
];
foreach ($fns as $f) {
    $rf = new ReflectionFunction($f);
    $bits = [];
    foreach ($rf->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : 'NONE';
        $def = $p->isDefaultValueAvailable() ? '='.var_export($p->getDefaultValue(), true) : '';
        $bits[] = $p->getName().':'.$t.($p->isPassedByReference() ? '&' : '').$def;
    }
    $ret = $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE';
    echo $f, "\t", implode(',', $bits), "\t→\t", $ret, "\n";
}
echo 'is_int_named=', var_export(is_int(value: 1), true), "\n";
echo 'is_callable_omit=', var_export(is_callable('strlen'), true), "\n";
