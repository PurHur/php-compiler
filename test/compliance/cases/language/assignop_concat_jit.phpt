--TEST--
Compound assignment: string .= (JIT)
--FILE--
<?php
$s = 'Hello';
$s .= ' World';
echo $s, "\n";
--EXPECT--
Hello World
