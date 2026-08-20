<?php
// Repro #32889 — AOT function-static string concat must persist across calls.
function f() {
    static $s = 'hi';
    $s .= '!';
    return $s;
}
function g() {
    static $s = 'hi';
    $s = $s . '!';
    return $s;
}
echo f(), f(), '|', g(), g(), "\n";
