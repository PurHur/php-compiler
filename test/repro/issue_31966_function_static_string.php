<?php
// Repro #31966 — AOT write to a function-static string variable.
function f() {
    static $s = 'y';
    $s = 'z';
    echo $s, "\n";
}
f();
