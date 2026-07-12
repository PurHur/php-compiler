--TEST--
stdlib DOMNode::isDefaultNamespace(null) — caller strict_types gate (#18215, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns="http://example.com"/>');
$root = $doc->documentElement;
echo (int) $root->isDefaultNamespace('http://example.com'), "\n";
echo (int) $root->isDefaultNamespace(null), "\n";
?>
--EXPECT--
1
0
