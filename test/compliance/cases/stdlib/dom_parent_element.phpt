--TEST--
stdlib DOMElement::$parentElement — living DOM property (#17293, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<html><body><div id="x"></div></body></html>');
$div = $doc->getElementById('x');
echo $div->parentElement->tagName, "\n";
$text = $doc->createTextNode('hi');
$div->appendChild($text);
echo ($text->parentElement === $div ? 'text_ok' : 'text_fail'), "\n";
echo ($doc->documentElement->parentElement === null ? 'root_null' : 'root_fail'), "\n";
?>
--EXPECT--
body
text_ok
root_null
