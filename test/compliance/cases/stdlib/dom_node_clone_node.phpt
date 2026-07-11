--TEST--
stdlib DOMNode::cloneNode() deep and shallow (#14381, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child><inner/></child></root>');
$child = $doc->documentElement->firstChild;
$deep = $child->cloneNode(true);
echo ($deep->firstChild && 'inner' === $deep->firstChild->nodeName) ? "deep_ok\n" : "deep_fail\n";
$shallow = $child->cloneNode(false);
echo null === $shallow->firstChild ? "shallow_ok\n" : "shallow_fail\n";
--EXPECT--
deep_ok
shallow_ok
