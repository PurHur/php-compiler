--TEST--
DOMDocument::createElementNS($ns, $name, $value) textContent/nodeValue/saveXML (#32302, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com', 'ex:item', 'hello');
echo 'text=', $el->textContent, "\n";
echo 'nodeValue=', $el->nodeValue, "\n";
echo 'xml=', $doc->saveXML($el), "\n";
$empty = $doc->createElementNS('http://example.com', 'ex:empty');
echo 'empty=', var_export($empty->textContent, true), "\n";
?>
--EXPECT--
text=hello
nodeValue=hello
xml=<ex:item xmlns:ex="http://example.com">hello</ex:item>
empty=''
