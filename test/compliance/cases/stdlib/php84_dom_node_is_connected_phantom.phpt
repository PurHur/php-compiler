--TEST--
stdlib DOMNode::$isConnected — not advertised on PHP 8.2 reference profile (#19653, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('x');
echo property_exists($el, 'isConnected') ? "fail\n" : "ok\n";
?>
--EXPECT--
ok
