--TEST--
simplexml_load_string attribute offsetGet + child cast under AOT (#27438)
--FILE--
<?php
$x = simplexml_load_string("<r a=\"1\"><c>2</c></r>");
var_export((string)$x["a"]);
echo "\n";
var_export((string)$x->c);
echo "\n";
--EXPECT--
'1'
'2'
