--TEST--
session_gc Reflection return int|false (#27855, session.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('session_gc');
echo 'arity=', $r->getNumberOfParameters();
echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$n = @session_gc();
echo 'gc=', var_export($n, true), ' type=', get_debug_type($n), "\n";
?>
--EXPECT--
arity=0 ret=int|false
gc=false type=bool
