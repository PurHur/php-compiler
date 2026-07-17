--TEST--
DOMXPath //namespace::* and path/namespace::* (#20170, ext/dom/xpath.c)
--FILE--
<?php
$xml = '<r xmlns:a="urn:a"><c xmlns:b="urn:b"/></r>';
$dom = new DOMDocument();
$dom->loadXML($xml);
$xp = new DOMXPath($dom);
$el = $dom->documentElement->firstChild;

$list = $xp->query('//namespace::*', $el);
echo '//namespace::* len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    $item = $list->item($i);
    echo $item->nodeName, '=', $item->namespaceURI, "\n";
}

$c = $xp->query('//c/namespace::*', $el);
echo '//c/namespace::* len=', $c->length, "\n";
for ($i = 0; $i < $c->length; $i++) {
    $item = $c->item($i);
    echo 'c:', $item->localName, '=', $item->namespaceURI, "\n";
}

$one = $xp->query('/r/namespace::a', $el);
echo '/r/namespace::a len=', $one->length,
    ' uri=', $one->length ? $one->item(0)->namespaceURI : 'n/a',
    "\n";

$filtered = $xp->query('//namespace::a', $el);
echo '//namespace::a len=', $filtered->length, "\n";
--EXPECT--
//namespace::* len=5
xmlns:xml=http://www.w3.org/XML/1998/namespace
xmlns:a=urn:a
xmlns:xml=http://www.w3.org/XML/1998/namespace
xmlns:a=urn:a
xmlns:b=urn:b
//c/namespace::* len=3
c:xml=http://www.w3.org/XML/1998/namespace
c:a=urn:a
c:b=urn:b
/r/namespace::a len=1 uri=urn:a
//namespace::a len=2
