<?php
/**
 * #28368 — openssl_error_string Reflection return string|false (openssl.stub.php).
 */
$r = new ReflectionFunction('openssl_error_string');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
$v = openssl_error_string();
echo 'runtime=', (false === $v || is_string($v)) ? 'ok' : gettype($v), "\n";
