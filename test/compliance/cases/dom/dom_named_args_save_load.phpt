--TEST--
DOMDocument::saveXML/loadXML named args — Zend stub names (#25182)
--FILE--
<?php
$d = new DOMDocument();
echo "saveXML=", (str_contains($d->saveXML(node: null), "<?xml") ? "ok" : "bad"), "\n";
echo "saveHTML=", (is_string($d->saveHTML(node: null)) ? "ok" : "bad"), "\n";
echo "saveXML_opts=", (str_contains($d->saveXML(options: 0), "<?xml") ? "ok" : "bad"), "\n";
echo "loadXML=", ($d->loadXML(source: "<r><a/></r>") ? "ok" : "bad"), "\n";
echo "clone=", $d->documentElement->cloneNode(deep: true)->tagName, "\n";
echo "item=", $d->documentElement->childNodes->item(index: 0)->tagName, "\n";
$xp = new DOMXPath($d);
$xp->registerPhpFunctions(restrict: null);
echo "registerPhp=ok\n";
--EXPECT--
saveXML=ok
saveHTML=ok
saveXML_opts=ok
loadXML=ok
clone=r
item=a
registerPhp=ok
