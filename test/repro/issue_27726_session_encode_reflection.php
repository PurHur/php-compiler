<?php
// Repro #27726 — session_encode Reflection must be string|false (session.stub.php)
$r = new ReflectionFunction('session_encode');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
