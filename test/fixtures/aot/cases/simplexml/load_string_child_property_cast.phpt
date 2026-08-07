--TEST--
simplexml_load_string child property + (string) cast under AOT (#28639)
--FILE--
<?php
$x = simplexml_load_string("<r><a>1</a><b>2</b></r>");
echo (string)$x->a, ",", (string)$x->b, "\n";
$y = $x->a;
echo (string)$y, "\n";
--EXPECT--
1,2
1
