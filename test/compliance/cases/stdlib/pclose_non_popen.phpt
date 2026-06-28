--TEST--
stdlib pclose() on non-popen stream — close handle and return 0 (#13305, ext/standard/exec.c)
--FILE--
<?php
declare(strict_types=1);

$h = fopen('php://memory', 'r');
$r = pclose($h);
echo 'pclose=' . var_export($r, true) . "\n";
echo 'is_resource=' . var_export(is_resource($h), true) . "\n";
--EXPECT--
pclose=0
is_resource=false
