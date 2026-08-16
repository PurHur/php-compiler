<?php
// DOM ReflectionProperty::$class / isPublic (#31639)
$rp = (new ReflectionClass(DOMNode::class))->getProperties()[0];
echo "dom_name=", $rp->getName(), "\n";
echo "dom_class=", var_export($rp->class, true), "\n";
try {
    echo "dom_public=", (int)$rp->isPublic(), "\n";
} catch (Throwable $t) {
    echo "dom_public_err=", get_class($t), ":", $t->getMessage(), "\n";
}

$inherited = (new ReflectionClass(DOMElement::class))->getProperty('nodeName');
echo "element_nodeName_class=", $inherited->class, "\n";

// XMLReader Reflection properties
$xr = new ReflectionClass(XMLReader::class);
echo "xmlreader_count=", count($xr->getProperties()), "\n";
echo "xmlreader_has_name=", (int)$xr->hasProperty('name'), "\n";
if ($xr->hasProperty('name')) {
    $np = $xr->getProperty('name');
    echo "xmlreader_name_class=", $np->class, " type=", $np->hasType() ? (string)$np->getType() : 'none', "\n";
}
