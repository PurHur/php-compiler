--TEST--
Spaceship operator (<=>) under JIT for integers
--FILE--
<?php
echo 1 <=> 2, 2 <=> 2, 3 <=> 2;
--EXPECT--
-101
