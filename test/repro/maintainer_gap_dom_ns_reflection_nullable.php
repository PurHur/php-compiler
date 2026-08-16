<?php

declare(strict_types=1);

// Reflection: *AttributeNS / getElementsByTagNameNS $namespace is ?string (#31469).

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
