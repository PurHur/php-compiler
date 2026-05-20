--TEST--
Compound assignment: string .= (VM)
--FILE--
<?php
$s = 'Hello';
$s .= ' World';
echo $s, "\n";
--EXPECT--
Hello World
