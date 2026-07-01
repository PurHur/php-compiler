--TEST--
stdlib DOMDocument::createElementNS (#14314, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com', 'ex:item', 'text');
echo $el->namespaceURI, "\n";
echo $el->localName, "\n";
echo $el->prefix, "\n";
echo $el->textContent, "\n";
?>
--EXPECT--
http://example.com
item
ex
text
