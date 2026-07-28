--TEST--
DOMNamedNodeMap::getNamedItem() matches Attr local name, not QName (#24332, ext/dom/namednodemap.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:x="urn:x" x:a="1" b="2"/>');
$m = $doc->documentElement->attributes;
echo 'local=', var_export($m->getNamedItem('a')?->nodeValue, true), "\n";
echo 'qname=', var_export($m->getNamedItem('x:a')?->nodeValue, true), "\n";
echo 'plain=', var_export($m->getNamedItem('b')?->nodeValue, true), "\n";
echo 'ns=', var_export($m->getNamedItemNS('urn:x', 'a')?->nodeValue, true), "\n";
echo 'offset=', var_export($m['a']?->nodeValue ?? null, true), "\n";
?>
--EXPECT--
local='1'
qname=NULL
plain='2'
ns='1'
offset='1'
