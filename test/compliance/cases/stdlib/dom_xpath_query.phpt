--TEST--
stdlib DOMXPath::query() descendant attribute predicate (#6066, ext/dom/xpath.c)
--FILE--
<?php
$xml = '<root><item id="1">a</item><item id="2">b</item></root>';
$doc = new DOMDocument();
$doc->loadXML($xml);
echo (int) class_exists('DOMXPath', false), "\n";
$xpath = new DOMXPath($doc);
$nodes = $xpath->query('//item[@id="2"]');
echo $nodes->length, "\n";
echo $nodes->item(0)->textContent, "\n";
?>
--EXPECT--
1
1
b
