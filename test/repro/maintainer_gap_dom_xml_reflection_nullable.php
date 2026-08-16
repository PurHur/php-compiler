<?php

declare(strict_types=1);

// Reflection stub nullability residual (#31501, re-#31469).

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
