<?php
/**
 * #26356 — get_defined_functions Reflection $exclude_disabled: bool
 * php-src: ext/standard/basic_functions.stub.php
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_26356_get_defined_functions_reflection.php'
 */
$r = new ReflectionFunction('get_defined_functions');
$p = $r->getParameters()[0];
echo 'param=$', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none';
echo ' opt=', $p->isOptional() ? 'yes' : 'no';
echo ' def=', var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : null, true), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
