--TEST--
dom DOMNode::isSameNode() node identity (#14379)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
$b = $doc->getElementsByTagName('b')->item(0);
echo (int) $a->isSameNode($a), "\n";
echo (int) $a->isSameNode($b), "\n";
--EXPECT--
1
0
