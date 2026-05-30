--TEST--
language eval() variable capture and echo (VM, issue #3358)
--FILE--
<?php
$x = 10;
eval('$y = $x + 1;');
echo $y, "\n";
eval('echo "hi\n";');
--EXPECT--
11
hi
