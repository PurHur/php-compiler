--TEST--
SimpleXML: SimpleXMLIterator child traversal (#6694, ext/simplexml/sxe.c)
--FILE--
<?php
$xml = simplexml_load_string('<root><a/><b/></root>');
$it = new SimpleXMLIterator($xml);
$names = '';
foreach ($it as $name => $node) {
    $names .= $name;
}
echo $names, "\n";
echo count($it), "\n";
--EXPECT--
ab
2
