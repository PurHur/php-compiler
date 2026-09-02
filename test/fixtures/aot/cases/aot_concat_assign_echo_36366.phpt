--TEST--
Language: AOT echo must read CONCAT+ASSIGN CV after AssignOp peephole fusion (#36366)
--FILE--
<?php
$out = 'hello' . "\n";
echo $out;

$lines = ['a', 'b'];
$out = implode("\n", $lines) . "\n";
echo $out;
--EXPECT--
hello
a
b
