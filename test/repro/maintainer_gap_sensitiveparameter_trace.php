<?php
/** Maintainer gap: SensitiveParameter stack frames show SensitiveParameterValue — Zend redacts args (Zend/zend_exceptions.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

function f(#[\SensitiveParameter] string $password) {
    throw new Exception('boom');
}
try {
    f('secret');
} catch (Throwable $e) {
    echo $e, "\n";
}
