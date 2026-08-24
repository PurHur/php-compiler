--TEST--
SimpleXMLElement attribute echo uses baked text under AOT (#34549)
--FILE--
<?php
$x = simplexml_load_string('<r a="1"><c id="2">t</c></r>');
echo $x['a'], "\n";
echo (string) $x['a'], "\n";
echo $x->c['id'], "\n";
--EXPECT--
1
1
2
