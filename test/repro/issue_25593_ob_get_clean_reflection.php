<?php
/**
 * #25593 — ob_get_clean Reflection return string|false
 * (ext/standard/basic_functions.stub.php / output.c).
 */
$r = new ReflectionFunction('ob_get_clean');
echo 'ob_get_clean=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$empty = ob_get_clean();
echo 'empty=', var_export($empty, true), "\n";
ob_start();
echo 'payload';
$got = ob_get_clean();
echo 'got=', var_export($got, true), "\n";
