--TEST--
keyed short list ["a" => $x] destructuring assignment
--FILE--
<?php
["a" => $x, "b" => $y] = ["a" => 1, "b" => 2];
echo $x, $y, "\n";
--EXPECT--
12
