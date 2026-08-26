<?php
// #35118 — AOT importNode(DOMAttr) must return Attr (xmlDocCopyNode / xmlCopyProp).
$src = new DOMDocument();
$src->loadXML('<r a="1"/>');
$dst = new DOMDocument();
$dst->loadXML('<r/>');
$attr = $src->documentElement->getAttributeNode('a');
$imp = $dst->importNode($attr, true);
echo 'type=', $imp->nodeType, ' nodeName=', $imp->nodeName, "\n";
echo 'name=', $imp->name, ' value=', $imp->value, "\n";
$dst->documentElement->setAttributeNode($imp);
echo 'xml=', trim($dst->saveXML($dst->documentElement)), "\n";
