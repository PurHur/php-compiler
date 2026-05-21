--TEST--
list() destructuring from array literal
--FILE--
<?php
list($a, $b) = array(1, 2);
echo $a, $b, "\n";
--EXPECT--
12
