<?php
// Repro #22546: by-ref param `$a =& $static` must not mutate the caller.
function f(&$a = null) {
    static $x = 0;
    $x++;
    if ($a === null) {
        $a =& $x;
    }
    return $x;
}
$r = null;
f($r);
echo var_export($r, true), "\n";
f($r);
echo var_export($r, true), "\n";

function g(&$a) {
    $a = 99;
}
$s = 1;
g($s);
echo var_export($s, true), "\n";
