<?php
/**
 * #28287 — dl Reflection return bool
 * (ext/standard/basic_functions.stub.php / dl.c).
 */
$r = new ReflectionFunction('dl');
echo 'dl=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_WARNING === $errno) {
        echo 'warn=1', "\n";
    }

    return true;
});
$ok = dl('x');
echo 'result=', var_export($ok, true), "\n";
