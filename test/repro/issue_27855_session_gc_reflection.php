<?php
// Repro #27855 — session_gc Reflection must be int|false (session.stub.php)
$r = new ReflectionFunction('session_gc');
echo 'arity=', $r->getNumberOfParameters();
echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$n = @session_gc();
echo 'gc=', var_export($n, true), ' type=', get_debug_type($n), "\n";
