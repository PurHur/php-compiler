<?php
/**
 * #25760 — strtok Reflection return string|false
 * (ext/standard/string.stub.php).
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_25760_strtok_reflection.php'
 */
$r = new ReflectionFunction('strtok');
echo 'strtok=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$tok = strtok('a b', ' ');
echo 'first=', ($tok === 'a') ? '1' : '0', "\n";
$tok = strtok(' ');
echo 'second=', ($tok === 'b') ? '1' : '0', "\n";
$tok = strtok(' ');
echo 'end=', (false === $tok) ? '1' : '0', "\n";
