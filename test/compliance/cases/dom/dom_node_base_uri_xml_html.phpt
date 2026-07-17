--TEST--
dom DOMNode::$baseURI resolves xml:base and HTML base href (#20199, re-#14453)
--FILE--
<?php
$xml = new DOMDocument();
$xml->loadXML('<r xml:base="http://ex/a/b/"><c xml:base="../x/"><d/></c></r>');
$d = $xml->documentElement->firstChild->firstChild;
echo 'xml=', $d->baseURI, "\n";

$html = new DOMDocument();
$html->loadHTML('<html><head><base href="http://ex/dir/"></head><body><div id="t">x</div></body></html>');
echo 'html=', $html->getElementById('t')->baseURI, "\n";
--EXPECT--
xml=http://ex/a/x/
html=http://ex/dir/
