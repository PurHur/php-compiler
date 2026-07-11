--TEST--
stdlib dom_import_simplexml() / simplexml_import_dom() round-trip (#6057, ext/dom/node.c)
--FILE--
<?php
$sxe = simplexml_load_string('<root><item id="1">a</item></root>');
$dom = dom_import_simplexml($sxe);
$back = simplexml_import_dom($dom);
echo $dom->nodeName, "\n";
echo $dom->getElementsByTagName('item')->item(0)->textContent, "\n";
echo (string) $back->item[0], "\n";
?>
--EXPECT--
root
a
a
