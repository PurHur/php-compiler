--TEST--
language: array integer-key write and read JIT (issue #107)
--FILE--
<?php
$a = [];
$a[1] = 'one';
$a[2] = 'two';
echo $a[1], $a[2], "\n";
--EXPECT--
onetwo
