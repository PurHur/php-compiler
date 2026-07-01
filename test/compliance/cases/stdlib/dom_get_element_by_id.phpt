--TEST--
stdlib DOMDocument::getElementById() without DTD returns null (#14378, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child id="target">x</child></root>');
echo null === $doc->getElementById('target') ? "null_ok\n" : "unexpected\n";
echo null === $doc->getElementById('missing') ? "missing_ok\n" : "missing_found\n";
--EXPECT--
null_ok
missing_ok
