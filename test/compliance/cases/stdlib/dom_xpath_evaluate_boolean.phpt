--TEST--
stdlib DOMXPath::evaluate() boolean/count scalars (#18392, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child id="1">a</child></root>');
$xpath = new DOMXPath($doc);
echo (int) $xpath->evaluate('boolean(//child)'), "\n";
echo (int) $xpath->evaluate('count(//child)'), "\n";
?>
--EXPECT--
1
1
