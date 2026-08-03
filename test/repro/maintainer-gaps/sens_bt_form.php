<?php
// Repro #27124 — debug_print_backtrace must show Object(SensitiveParameterValue) (Zend match).
function bt(#[\SensitiveParameter] $password) {
    debug_print_backtrace();
}
bt('secret');
$t = (function (#[\SensitiveParameter] $p) {
    return debug_backtrace();
})('secret');
echo get_class($t[0]['args'][0]), "\n";
echo ($t[0]['args'][0] instanceof SensitiveParameterValue) ? "instanceof_ok\n" : "instanceof_fail\n";
