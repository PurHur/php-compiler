--TEST--
DOMElement/DOMDocument *AttributeNS / getElementsByTagNameNS Reflection $namespace ?string (#31469)
--FILE--
<?php
$methods = [
    ['DOMElement', 'getAttributeNS'],
    ['DOMElement', 'hasAttributeNS'],
    ['DOMElement', 'setAttributeNS'],
    ['DOMElement', 'removeAttributeNS'],
    ['DOMElement', 'getAttributeNodeNS'],
    ['DOMElement', 'getElementsByTagNameNS'],
    ['DOMDocument', 'getElementsByTagNameNS'],
];

foreach ($methods as [$cls, $method]) {
    $p = (new ReflectionMethod($cls, $method))->getParameters()[0];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    echo $cls, '::', $method, ' namespace=', $type, "\n";
}

$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$el = $d->documentElement;
echo 'runtime_getAttributeNS_null=', var_export($el->getAttributeNS(null, 'a'), true), "\n";
echo 'runtime_hasAttributeNS_null=', var_export($el->hasAttributeNS(null, 'a'), true), "\n";
?>
--EXPECT--
DOMElement::getAttributeNS namespace=?string
DOMElement::hasAttributeNS namespace=?string
DOMElement::setAttributeNS namespace=?string
DOMElement::removeAttributeNS namespace=?string
DOMElement::getAttributeNodeNS namespace=?string
DOMElement::getElementsByTagNameNS namespace=?string
DOMDocument::getElementsByTagNameNS namespace=?string
runtime_getAttributeNS_null='1'
runtime_hasAttributeNS_null=true
