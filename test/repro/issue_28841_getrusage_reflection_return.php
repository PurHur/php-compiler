<?php
/**
 * #28841 — getrusage Reflection return array|false (basic_functions.stub.php).
 */
$r = new ReflectionFunction('getrusage');
echo 'getrusage=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$u = getrusage();
echo 'getrusage_runtime=', (false === $u || is_array($u)) ? 'ok' : gettype($u), "\n";
