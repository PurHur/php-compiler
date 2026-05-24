--TEST--
keyed list destructuring with string keys (JIT, issue #1234)
--FILE--
<?php
["first" => $a, "second" => $b] = array("first" => "x", "second" => "y");
echo $a, $b, "\n";
--EXPECT--
xy
