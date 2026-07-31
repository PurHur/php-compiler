--TEST--
Dom\Attr::$name is QName; legacy DOMAttr::$name stays local (#26024, ext/dom/attr.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$h = Dom\HTMLDocument::createEmpty();
$root = $h->createElement('root');
$h->append($root);
$attr = $h->createAttributeNS('http://example.com', 'ex:foo');
$attr->value = 'v';
$root->setAttributeNodeNS($attr);
echo 'living name=', $attr->name,
    ' nodeName=', $attr->nodeName,
    ' localName=', $attr->localName,
    ' prefix=', $attr->prefix,
    "\n";

$d = new DOMDocument();
$d->appendChild($d->createElement('r'));
$legacy = $d->createAttributeNS('http://example.com', 'ex:bar');
echo 'legacy name=', $legacy->name,
    ' nodeName=', $legacy->nodeName,
    ' localName=', $legacy->localName,
    "\n";

$attr->rename('http://example.com', 'ex:baz');
echo 'renamed name=', $attr->name, ' nodeName=', $attr->nodeName, "\n";
?>
--EXPECT--
living name=ex:foo nodeName=ex:foo localName=foo prefix=ex
legacy name=bar nodeName=ex:bar localName=bar
renamed name=ex:baz nodeName=ex:baz
