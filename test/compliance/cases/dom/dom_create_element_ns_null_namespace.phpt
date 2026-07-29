--TEST--
DOMDocument::createElementNS(null) vs "" — AOT/VM namespaceURI (#24923)
--FILE--
<?php
$d = new DOMDocument();
$el = $d->createElementNS(null, 'x');
echo 'null_name=', $el->nodeName, "\n";
echo 'null_ns_is_null=', ($el->namespaceURI === null ? '1' : '0'), "\n";
echo 'null_local=', $el->localName, "\n";

$el2 = $d->createElementNS('urn:t', 'p:y');
echo 'ns_name=', $el2->nodeName, "\n";
echo 'ns_uri=', $el2->namespaceURI, "\n";
echo 'ns_local=', $el2->localName, "\n";
echo 'ns_prefix=', $el2->prefix, "\n";

$el3 = $d->createElementNS('', 'z');
echo 'empty_name=', $el3->nodeName, "\n";
echo 'empty_ns_is_empty=', ($el3->namespaceURI === '' ? '1' : '0'), "\n";
echo 'empty_ns_is_null=', ($el3->namespaceURI === null ? '1' : '0'), "\n";

$m = new ReflectionMethod('DOMDocument', 'createElementNS');
$p = $m->getParameters()[0];
echo 'ref_name=', $p->getName(), ' nullable=', ($p->allowsNull() ? '1' : '0'), "\n";
?>
--EXPECT--
null_name=x
null_ns_is_null=1
null_local=x
ns_name=p:y
ns_uri=urn:t
ns_local=y
ns_prefix=p
empty_name=z
empty_ns_is_empty=1
empty_ns_is_null=0
ref_name=namespace nullable=1
