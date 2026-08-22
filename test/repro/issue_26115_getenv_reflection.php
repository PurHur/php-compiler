<?php
/**
 * #26115 — getenv Reflection return array|string|false
 * (ext/standard/basic_functions.stub.php).
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_26115_getenv_reflection.php'
 */
$r = new ReflectionFunction('getenv');
echo 'getenv=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$all = getenv();
echo 'noarg=', is_array($all) ? 'array' : gettype($all), "\n";
putenv('PHPC_GETENV_REFLECT=1');
$one = getenv('PHPC_GETENV_REFLECT');
echo 'named=', ($one === '1') ? '1' : '0', "\n";
putenv('PHPC_GETENV_REFLECT');
