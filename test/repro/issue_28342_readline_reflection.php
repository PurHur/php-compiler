<?php
/** Repro #28342 — readline Reflection return is string|false (php-src readline.stub.php). */
$r = new ReflectionFunction('readline');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
