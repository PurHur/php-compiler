--TEST--
stdlib preg_match() JIT pattern:/subject:/matches: named parameters (#10035)
--JIT--
--FILE--
<?php
declare(strict_types=1);

$m = [];
preg_match(pattern: '/a/', subject: 'abc', matches: $m);
echo $m[0], "\n";
--EXPECT--
a
