--TEST--
Spaceship operator (<=>) for numbers and strings
--FILE--
<?php
echo 1 <=> 2, 2 <=> 2, 3 <=> 2, "\n";
echo 'b' <=> 'a', 'a' <=> 'a', 'a' <=> 'b', "\n";
--EXPECT--
-101
10-1
