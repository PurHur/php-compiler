--TEST--
AOT: DOMElement AttributeNodeNS + createAttributeNS user-script (#19268)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<e xmlns:ns="urn:x" ns:a="1"/>');
$el = $doc->documentElement;
$a = $el->getAttributeNodeNS('urn:x', 'a');
echo ($a instanceof DOMAttr ? 'ns:a' : 'null'), "\n";
echo (null === $el->getAttributeNodeNS('urn:x', 'missing') ? 'NULL' : 'hit'), "\n";
$new = $doc->createAttributeNS('urn:x', 'ns:b');
echo (null === $el->setAttributeNodeNS($new) ? 'NULL' : 'prev'), "\n";
$repl = $doc->createAttributeNS('urn:x', 'ns:a');
$old = $el->setAttributeNodeNS($repl);
echo ($old instanceof DOMAttr ? 'replaced' : 'null'), "\n";
--EXPECT--
ns:a
NULL
NULL
replaced
