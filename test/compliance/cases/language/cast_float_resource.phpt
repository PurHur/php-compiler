--TEST--
Language: (float) cast on stream resource — resource id as float (#15014, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
$expected = (float) get_resource_id($h);
$actual = (float) $h;
echo $expected === $actual ? 'match' : 'mismatch';
echo "\n";
--EXPECT--
match
