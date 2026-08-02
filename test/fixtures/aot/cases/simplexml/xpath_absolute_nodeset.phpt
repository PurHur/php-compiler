--TEST--
SimpleXMLElement::xpath() absolute path node-set under AOT (#26911)
--FILE--
<?php
$x = simplexml_load_string("<r><a>1</a><a>2</a></r>");
$n = $x->xpath("/r/a");
echo count($n), ":", (string)$n[0], "\n";
echo (string)$n[1], "\n";
--EXPECT--
2:1
2
