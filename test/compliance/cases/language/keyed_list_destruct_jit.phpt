--TEST--
keyed short list destructuring assignment (JIT)
--FILE--
<?php
["first" => $a, "second" => $b] = ["first" => "x", "second" => "y"];
echo $a, $b, "\n";
--EXPECT--
xy
