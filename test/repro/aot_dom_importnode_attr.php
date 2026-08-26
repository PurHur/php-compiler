<?php
// #35118 — AOT importNode(DOMAttr) must copy the Attr (php-src xmlDocCopyNode / xmlCopyProp).
// getAttributeNode then importNode:
$src = new DOMDocument();
$src->loadXML('<r a="1"/>');
$attr = $src->documentElement->getAttributeNode('a');
$dst = new DOMDocument();
$dst->loadXML('<r/>');
$imp = $dst->importNode($attr, true);
echo 'type=', $imp->nodeType, ' name=', $imp->nodeName, ' val=', $imp->nodeValue, "\n";
echo 'attr_name=', $imp->name, ' attr_value=', $imp->value, "\n";
echo 'owner=', ($imp->ownerElement === null ? 'null' : 'set'), "\n";
$dst->documentElement->setAttributeNode($imp);
echo 'xml=', trim($dst->saveXML($dst->documentElement)), "\n";
echo 'owner_after=', $imp->ownerElement->tagName, "\n";

// createAttribute orphan then importNode:
$src2 = new DOMDocument();
$a = $src2->createAttribute('b');
$a->value = '2';
$dst2 = new DOMDocument();
$dst2->loadXML('<r/>');
$imp2 = $dst2->importNode($a, true);
echo 'orphan_type=', $imp2->nodeType, ' orphan_name=', $imp2->name, ' orphan_val=', $imp2->value, "\n";
$dst2->documentElement->setAttributeNode($imp2);
echo 'orphan_xml=', trim($dst2->saveXML($dst2->documentElement)), "\n";
