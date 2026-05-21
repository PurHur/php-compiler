--TEST--
short list [] destructuring assignment
--FILE--
<?php
[$x, $y] = array('a', 'b');
echo $x, $y, "\n";
--EXPECT--
ab
