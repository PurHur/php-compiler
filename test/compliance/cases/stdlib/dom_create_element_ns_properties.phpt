--TEST--
stdlib DOM createElementNS prefix/namespaceURI (#15381, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com/ns', 'ex:a');
echo $el->prefix, "\n";
echo $el->namespaceURI, "\n";
?>
--EXPECT--
ex
http://example.com/ns
