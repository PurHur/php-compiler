<?php
/**
 * Maintainer gap probe (re-#31693 / #31858): #[\SensitiveParameter] in getTraceAsString.
 *
 * Under php-src compiled default zend.exception_ignore_args=Off, Zend prints
 * Object(SensitiveParameterValue) — not empty f(). Ubuntu production php.ini sets On,
 * which omits ALL args (f()) and must not be compared to the guest compiled default
 * (see #28061 / src/cli.php). Match INIs before claiming a SensitiveParameter bug.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('zend.exception_ignore_args', '0');

function f(#[\SensitiveParameter] string $password) {
    throw new Exception('boom');
}
try {
    f('secret');
} catch (Throwable $e) {
    $as = $e->getTraceAsString();
    echo $as, "\n";
    echo str_contains($as, 'Object(SensitiveParameterValue)') ? "object_form\n" : "no_object_form\n";
    echo str_contains($as, 'secret') ? "leaked\n" : "no_leak\n";
    echo (string) ini_get('zend.exception_ignore_args'), "\n";
}
