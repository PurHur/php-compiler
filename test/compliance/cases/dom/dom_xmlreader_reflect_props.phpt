--TEST--
DOM*/XMLReader ReflectionProperty $class + XMLReader getProperties (#31639)
--FILE--
<?php
$rp = (new ReflectionClass(DOMNode::class))->getProperties()[0];
echo "dom_name=", $rp->getName(), "\n";
echo "dom_class=", var_export($rp->class, true), "\n";
echo "dom_public=", (int)$rp->isPublic(), "\n";

$inherited = (new ReflectionClass(DOMElement::class))->getProperty('nodeName');
echo "element_nodeName_class=", $inherited->class, "\n";

$xr = new ReflectionClass(XMLReader::class);
echo "xmlreader_count=", count($xr->getProperties()), "\n";
echo "xmlreader_has_name=", (int)$xr->hasProperty('name'), "\n";
$np = $xr->getProperty('name');
echo "xmlreader_name_class=", $np->class, " type=", (string)$np->getType(), "\n";
?>
--EXPECT--
dom_name=nodeName
dom_class='DOMNode'
dom_public=1
element_nodeName_class=DOMNode
xmlreader_count=14
xmlreader_has_name=1
xmlreader_name_class=XMLReader type=string
