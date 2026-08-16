--TEST--
DOM/XML Reflection residual stub nullability (#31501, re-#31469)
--FILE--
<?php
$checks = [
    ['DOMNamedNodeMap', 'getNamedItemNS', 0],
    ['DOMDocument', 'registerNodeClass', 1],
    ['DOMDocument', 'adoptNode', 0],
    ['DOMDocument', 'saveXML', 0],
    ['DOMDocument', 'saveHTML', 0],
    ['DOMNode', 'insertBefore', 1],
    ['DOMImplementation', 'createDocument', 0],
    ['DOMImplementation', 'createDocument', 2],
    ['XMLReader', 'expand', 0],
    ['XMLWriter', 'startDocument', 0],
    ['XMLWriter', 'startDocument', 1],
    ['XMLWriter', 'startDocument', 2],
    ['XMLWriter', 'writeElement', 1],
];

foreach ($checks as [$cls, $method, $i]) {
    $p = (new ReflectionMethod($cls, $method))->getParameters()[$i];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    echo $cls, '::', $method, ' arg', $i, '=', $type, "\n";
}

$doc = new DOMDocument();
$doc->loadXML('<root xmlns:a="urn:a"><a:x a:y="1"/></root>');
$map = $doc->documentElement->firstChild->attributes;
echo 'runtime_getNamedItemNS_null=', var_export($map->getNamedItemNS(null, 'y'), true), "\n";

$doc->registerNodeClass('DOMElement', null);
echo "runtime_registerNodeClass_null=ok\n";

$impl = new DOMImplementation();
$d2 = $impl->createDocument(null, 'root');
echo 'runtime_createDocument_null=', $d2->documentElement->tagName, "\n";
?>
--EXPECT--
DOMNamedNodeMap::getNamedItemNS arg0=?string
DOMDocument::registerNodeClass arg1=?string
DOMDocument::adoptNode arg0=DOMNode
DOMDocument::saveXML arg0=?DOMNode
DOMDocument::saveHTML arg0=?DOMNode
DOMNode::insertBefore arg1=?DOMNode
DOMImplementation::createDocument arg0=?string
DOMImplementation::createDocument arg2=?DOMDocumentType
XMLReader::expand arg0=?DOMNode
XMLWriter::startDocument arg0=?string
XMLWriter::startDocument arg1=?string
XMLWriter::startDocument arg2=?string
XMLWriter::writeElement arg1=?string
runtime_getNamedItemNS_null=NULL
runtime_registerNodeClass_null=ok
runtime_createDocument_null=root
