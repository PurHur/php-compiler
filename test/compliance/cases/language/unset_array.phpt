--TEST--
unset() on an array offset
--FILE--
<?php
$a = array("k" => 1, "keep" => 2);
unset($a["k"]);
echo isset($a["k"]) ? "y" : "n", "\n";
echo $a["keep"], "\n";
--EXPECT--
n
2
