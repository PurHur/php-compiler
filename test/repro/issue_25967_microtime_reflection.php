<?php
/**
 * #25967 — microtime Reflection return string|float
 * (ext/standard/basic_functions.stub.php).
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_25967_microtime_reflection.php'
 */
$r = new ReflectionFunction('microtime');
echo 'microtime=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'as_float=', gettype(microtime(true)), "\n";
echo 'as_string=', gettype(microtime(false)), "\n";
