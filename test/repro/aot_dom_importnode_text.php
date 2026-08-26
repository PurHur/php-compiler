<?php
// #35043 — AOT importNode(DOMText) must copy a text node (php-src xmlDocCopyNode).
// createTextNode path:
$src = new DOMDocument();
$t = $src->createTextNode('hi');
$dst = new DOMDocument();
$dst->loadXML('<r/>');
$imp = $dst->importNode($t, true);
echo 'create_type=', $imp->nodeType, ' name=', $imp->nodeName, ' val=', $imp->nodeValue, "\n";
$dst->documentElement->appendChild($imp);
echo 'create_xml=', trim($dst->saveXML($dst->documentElement)), "\n";

// loadXML #text via nextSibling:
$d1 = new DOMDocument();
$d1->loadXML('<r><a/>hello<b/></r>');
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$txt = $d1->documentElement->firstChild->nextSibling;
$imp2 = $d2->importNode($txt, true);
echo 'sib_type=', $imp2->nodeType, ' name=', $imp2->nodeName, ' val=', $imp2->nodeValue, "\n";
$d2->documentElement->appendChild($imp2);
echo 'sib_xml=', trim($d2->saveXML($d2->documentElement)), "\n";
