--TEST--
stdlib DOMNode::getLineNo matches Zend xmlGetLineNo (#32489, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
echo $doc->documentElement->getLineNo(), '|', $doc->documentElement->firstChild->getLineNo(), "\n";
$lead = new DOMDocument();
$lead->loadXML("\n<root/>");
echo $lead->documentElement->getLineNo(), "\n";
$multi = new DOMDocument();
$multi->loadXML("<root>\n<child/>\n</root>");
echo $multi->documentElement->getLineNo(), "\n";
--EXPECT--
1|1
2
1
