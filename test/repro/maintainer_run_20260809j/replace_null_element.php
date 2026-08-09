<?php
// #29309 — null array *elements* must not emit parameter-level E_DEPRECATED
error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr) {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        echo 'DEP: ', $errstr, "\n";
        return true;
    }
    return false;
});

echo 'sr=', var_export(str_replace(['a'], [null], 'ab'), true), "\n";
echo 'si=', var_export(str_ireplace(['A'], [null], 'Ab'), true), "\n";
echo 'su=', var_export(substr_replace('abc', [null], 1, 1), true), "\n";
echo 'ss=', var_export(str_replace([null], ['X'], 'a'), true), "\n";
echo 'param=', var_export(str_replace(null, 'x', 'a'), true), "\n";
