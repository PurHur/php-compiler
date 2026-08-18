--TEST--
stdlib DOMNode::cloneNode saveXML matches xmlNodeDump (#32355, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child id="1"><inner/></child></root>');
$child = $doc->documentElement->firstChild;
$deep = $child->cloneNode(true);
$shallow = $child->cloneNode(false);
echo $deep->nodeName, '|', $doc->saveXML($deep), '|', $doc->saveXML($shallow), "END\n";
--EXPECT--
child|<child id="1"><inner/></child>|<child id="1"/>END
