--TEST--
language eval() variable capture (JIT, issue #4652)
--FILE--
<?php
$x = 1;
eval('$x = $x + 41;');
echo $x, "\n";
--EXPECT--
42
