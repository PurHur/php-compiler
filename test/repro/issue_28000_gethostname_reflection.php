<?php
// Repro #28000 — gethostname Reflection must be string|false (basic_functions.stub.php)
$r = new ReflectionFunction('gethostname');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
$h = gethostname();
echo 'runtime=', (is_string($h) || $h === false) ? 'ok' : gettype($h), "\n";
