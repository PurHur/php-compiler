--TEST--
keyed list destructuring with string keys (issue #1234)
--FILE--
<?php
["a" => $x, "b" => $y] = array("a" => 1, "b" => 2);
echo $x, $y, "\n";
["name" => $name] = array("name" => "Ada");
echo $name, "\n";
--EXPECT--
12
Ada
