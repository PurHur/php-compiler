--TEST--
DOMNode::C14N() with comments (#dom-c14n-comments, php-src ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><!--hi--><child>text</child></root>');
$expected = '<root><!--hi--><child>text</child></root>';
$out = $doc->documentElement->C14N(false, true);
echo ($expected === $out) ? "ok\n" : "fail\n";
?>
--EXPECT--
ok

