<?php
/**
 * Repro #5626 — array_reduce() must preserve enum case objects through closures
 * (including collect-into-[] with inline initial array literal).
 */
enum E: int { case A = 1; case B = 2; }

$cases = [E::A, E::B];
$r = array_reduce($cases, function ($carry, $item) {
    return $carry === null ? $item : $item;
});
var_export($r);
echo "\n";

$collected = array_reduce([E::A, E::B], function ($carry, $item) {
    $carry[] = $item;
    return $carry;
}, []);
foreach ($collected as $i => $v) {
    echo $i, ':', (is_object($v) ? $v->name : get_debug_type($v)), "\n";
}
