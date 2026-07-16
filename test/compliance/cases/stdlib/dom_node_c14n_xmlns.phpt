--TEST--
DOMNode::C14N() emits in-scope xmlns; exclusive omits unused (#19467, ext/dom/node.c)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root xmlns:a="urn:a" xmlns:unused="urn:u"><a:x b="1">t</a:x></root>');
$n = $dom->documentElement->firstChild;
$incl = $n->C14N();
$excl = $n->C14N(true);
echo ($incl === '<a:x xmlns:a="urn:a" xmlns:unused="urn:u" b="1">t</a:x>') ? 'incl ' : 'incl-fail ';
echo ($excl === '<a:x xmlns:a="urn:a" b="1">t</a:x>') ? "excl\n" : "excl-fail\n";
$dom->loadXML('<root xmlns:a="urn:a"><outer><a:x>t</a:x></outer></root>');
$outer = $dom->documentElement->firstChild;
echo ($outer->C14N(true) === '<outer><a:x xmlns:a="urn:a">t</a:x></outer>') ? "nested\n" : "nested-fail\n";
?>
--EXPECT--
incl excl
nested
