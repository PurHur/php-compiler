--TEST--
DOMNameSpaceNode + XPath namespace::* axis (#20097, ext/dom/php_dom.stub.php)
--FILE--
<?php
echo 'DOMNameSpaceNode=', class_exists('DOMNameSpaceNode') ? 'yes' : 'no', "\n";
$d = new DOMDocument();
$d->loadXML('<r xmlns:foo="urn:foo" xmlns="urn:default"/>');
$xp = new DOMXPath($d);
$n = $xp->query('namespace::*', $d->documentElement);
echo 'len=', $n->length, "\n";
for ($i = 0; $i < $n->length; $i++) {
    $item = $n->item($i);
    echo get_class($item),
        ' nodeName=', $item->nodeName,
        ' prefix=', var_export($item->prefix, true),
        ' localName=', var_export($item->localName, true),
        ' namespaceURI=', var_export($item->namespaceURI, true),
        ' nodeType=', $item->nodeType,
        "\n";
}
$one = $xp->query('namespace::foo', $d->documentElement);
echo 'namespace::foo len=', $one->length,
    ' uri=', $one->length ? $one->item(0)->namespaceURI : 'n/a',
    "\n";
--EXPECT--
DOMNameSpaceNode=yes
len=3
DOMNameSpaceNode nodeName=xmlns:xml prefix='xml' localName='xml' namespaceURI='http://www.w3.org/XML/1998/namespace' nodeType=18
DOMNameSpaceNode nodeName=xmlns prefix='' localName='xmlns' namespaceURI='urn:default' nodeType=18
DOMNameSpaceNode nodeName=xmlns:foo prefix='foo' localName='foo' namespaceURI='urn:foo' nodeType=18
namespace::foo len=1 uri=urn:foo
