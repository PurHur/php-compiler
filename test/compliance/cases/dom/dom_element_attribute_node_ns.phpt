--TEST--
DOMElement::getAttributeNodeNS()/setAttributeNodeNS() (#19265)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r xmlns:ns="urn:x"><e ns:a="1"/></r>');
$el = $doc->documentElement->firstChild;
$a = $el->getAttributeNodeNS('urn:x', 'a');
echo ($a instanceof DOMAttr ? $a->nodeName . '=' . $a->nodeValue : 'null'), "\n";
echo var_export($el->getAttributeNodeNS('urn:x', 'missing'), true), "\n";
$new = $doc->createAttributeNS('urn:x', 'ns:b');
$new->value = '2';
echo var_export($el->setAttributeNodeNS($new), true), "\n";
echo $el->getAttributeNS('urn:x', 'b'), "\n";
$repl = $doc->createAttributeNS('urn:x', 'ns:a');
$repl->value = '9';
$old = $el->setAttributeNodeNS($repl);
echo ($old instanceof DOMAttr ? $old->nodeName . '=' . $old->nodeValue : 'null'), "\n";
echo $el->getAttributeNS('urn:x', 'a'), "\n";
?>
--EXPECT--
ns:a=1
NULL
NULL
2
ns:a=1
9
