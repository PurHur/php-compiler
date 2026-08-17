--TEST--
DOMNode/DOMDocument/DOMXPath declared props + schemaTypeInfo/Entity encoding (#31753)
--FILE--
<?php
echo "DOMNode has attributes=", (int)(new ReflectionClass(DOMNode::class))->hasProperty('attributes'), "\n";
echo "DOMNode has namespaceURI=", (int)(new ReflectionClass(DOMNode::class))->hasProperty('namespaceURI'), "\n";
echo "DOMDocument has doctype=", (int)(new ReflectionClass(DOMDocument::class))->hasProperty('doctype'), "\n";
echo "DOMDocument has implementation=", (int)(new ReflectionClass(DOMDocument::class))->hasProperty('implementation'), "\n";
echo "DOMXPath has document=", (int)(new ReflectionClass(DOMXPath::class))->hasProperty('document'), "\n";
echo "DOMXPath has registerNodeNamespaces=", (int)(new ReflectionClass(DOMXPath::class))->hasProperty('registerNodeNamespaces'), "\n";
echo "DOMElement has schemaTypeInfo=", (int)(new ReflectionClass(DOMElement::class))->hasProperty('schemaTypeInfo'), "\n";
echo "DOMAttr has schemaTypeInfo=", (int)(new ReflectionClass(DOMAttr::class))->hasProperty('schemaTypeInfo'), "\n";
echo "DOMEntity has actualEncoding=", (int)(new ReflectionClass(DOMEntity::class))->hasProperty('actualEncoding'), "\n";

$ns = (new ReflectionClass(DOMNode::class))->getProperty('namespaceURI');
echo "namespaceURI type=", (string)$ns->getType(), " class=", $ns->class, "\n";

$d = new DOMDocument();
$d->loadXML('<r>txt</r>');
$t = $d->documentElement->firstChild;
echo "text_attrs=", var_export($t->attributes, true), "\n";
echo "el_schema=", var_export($d->documentElement->schemaTypeInfo, true), "\n";
try {
    $d->documentElement->schemaTypeInfo = 1;
    echo "schema_write=ok\n";
} catch (Throwable $e) {
    echo "schema_write=", get_class($e), "\n";
}

$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r [<!ENTITY e "x">]><r>&e;</r>');
$ent = $d2->doctype->entities->getNamedItem('e');
echo "ent_encoding=", var_export($ent->encoding, true), "\n";
?>
--EXPECT--
DOMNode has attributes=1
DOMNode has namespaceURI=1
DOMDocument has doctype=1
DOMDocument has implementation=1
DOMXPath has document=1
DOMXPath has registerNodeNamespaces=1
DOMElement has schemaTypeInfo=1
DOMAttr has schemaTypeInfo=1
DOMEntity has actualEncoding=1
namespaceURI type=?string class=DOMNode
text_attrs=NULL
el_schema=NULL
schema_write=Error
ent_encoding=NULL
