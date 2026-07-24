<?php
/**
 * Issue #22923 — static $a = $param must compile-fatal on 8.2 reference profile.
 */
function f($x) {
    static $a = $x;
    return $a;
}
echo f(1), ",", f(2), "\n";
