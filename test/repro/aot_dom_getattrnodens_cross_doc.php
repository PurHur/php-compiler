<?php
// #35131 — AOT getAttributeNodeNS must use source documentElement XML after a second loadXML.
$s = new DOMDocument();
$s->loadXML('<r xmlns:p="urn:x" p:a="1"/>');
$d = new DOMDocument();
$d->loadXML('<r/>');
$a = $s->documentElement->getAttributeNodeNS('urn:x', 'a');
echo null === $a ? "null\n" : ('type='.$a->nodeType.' name='.$a->nodeName.' val='.$a->value."\n");
if (null !== $a) {
    $imp = $d->importNode($a, true);
    echo 'imp_type='.$imp->nodeType.' imp_name='.$imp->nodeName.' imp_val='.$imp->value."\n";
}
// Single-doc control (must stay green).
$s2 = new DOMDocument();
$s2->loadXML('<r xmlns:p="urn:x" p:a="1"/>');
$a2 = $s2->documentElement->getAttributeNodeNS('urn:x', 'a');
echo null === $a2 ? "single_null\n" : ('single='.$a2->nodeType.'|'.$a2->nodeName.'|'.$a2->value."\n");
