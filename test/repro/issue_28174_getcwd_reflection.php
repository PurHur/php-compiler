<?php
/**
 * #28174 — getcwd Reflection return string|false
 * (ext/standard/basic_functions.stub.php / dir.c).
 */
$r = new ReflectionFunction('getcwd');
echo 'getcwd=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$cwd = getcwd();
echo 'cwd_ok=', is_string($cwd) ? '1' : '0', "\n";
