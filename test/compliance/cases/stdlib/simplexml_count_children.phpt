--TEST--
SimpleXML: count() returns direct element child cardinality (#18039, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r><a/><b/></r>');
echo count($x), "\n";
echo count(simplexml_load_string('<r/>')), "\n";
echo count(simplexml_load_string('<r>text</r>')), "\n";
--EXPECT--
2
0
0
