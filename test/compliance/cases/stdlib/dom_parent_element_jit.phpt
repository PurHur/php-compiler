--TEST--
stdlib DOMElement::$parentElement JIT — living DOM property (#17293, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<html><body><div id="x"></div></body></html>');
$div = $doc->getElementById('x');
echo $div->parentElement->tagName, "\n";
?>
--EXPECT--
body
